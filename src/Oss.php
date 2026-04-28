<?php

namespace Maxlcoder\LaravelOss;

use AlibabaCloud\Credentials\Credential;
use AlibabaCloud\Oss\V2\Client;
use AlibabaCloud\Oss\V2\Config;
use AlibabaCloud\Oss\V2\Credentials\StaticCredentialsProvider;
use AlibabaCloud\Oss\V2\Models\GetObjectRequest;
use AlibabaCloud\Oss\V2\Models\ObjectACLType;
use AlibabaCloud\Oss\V2\Models\PutObjectRequest;
use Carbon\Carbon;
use DateInterval;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Oss
{
    protected $config;
    protected $credential;


    protected $accessKeyId;
    protected $accessKeySecret;
    protected $bucket;
    protected $region;
    protected $path;


    public function __construct()
    {
        $this->config = config('oss');
        $this->accessKeyId = $this->config['access_key'];
        $this->accessKeySecret = $this->config['secret_key'];
        $this->bucket = $this->config['bucket'] ?? '';
        $this->region = $this->config['region'] ?? 'cn-hangzhou';
        $this->path = $this->config['path'] ?? 'dev';

        // 重新处理凭证
        $this->credential = $this->getCredential();
        $this->accessKeyId = $this->credential->getAccessKeyId();
        $this->accessKeySecret = $this->credential->getAccessKeySecret();

    }

    /**
     * 统一凭证
     */
    private function getCredential()
    {
        $config = [
            'type' => 'access_key',
            'accessKeyId' => $this->accessKeyId,
            'accessKeySecret' => $this->accessKeySecret,
        ];
        // 是否使用角色授权
        if (!empty($this->config['role_arn'])) {

            $policy = [
                'Version' => '1',
                'Statement' => [
                    [
                        'Effect' => 'Allow',
                        'Action' => ['oss:PutObject'],
                        'Resource' => ["acs:oss:*:*:$this->bucket/*"]
                    ]
                ]
            ];

            $config = [
                'type' => 'ram_role_arn',
                'accessKeyId' => $this->accessKeyId,
                'accessKeySecret' => $this->accessKeySecret,
                // 要扮演的RAM角色ARN，示例值：acs:ram::123456789012****:role/adminrole，可以通过环境变量ALIBABA_CLOUD_ROLE_ARN设置role_arn
                'roleArn' => $this->config['role_arn'],
                // 角色会话名称，可以通过环境变量ALIBABA_CLOUD_ROLE_SESSION_NAME设置role_session_name
                'roleSessionName' => $this->config['role_session_name'] ?? 'sts-upload',
                // 设置更小的权限策略，非必填。示例值：{"Statement": [{"Action": ["*"],"Effect": "Allow","Resource": ["*"]}],"Version":"1"}
                'policy' => json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                // 会话过期时间，非必填，默认值3600，指定 900 最少
                'roleSessionExpiration' => 900,
            ];
        }
        $credConfig = new Credential\Config($config);

        $credential = new Credential($credConfig);
        return $credential->getCredential();
    }


    /**
     * 前端直传，表单上传，生成服务端签名
     *
     * @param  int  $maxSize 最大文件大小
     * @return array
     */
    public function signUpload($dir = '', $maxSize = 1048576000)
    {
        // 从环境变量中获取访问凭证。运行本示例代码之前，请确保已设置环境变量ALIBABA_CLOUD_ACCESS_KEY_ID和ALIBABA_CLOUD_ACCESS_KEY_SECRET。
        $accessKeyId = $this->credential->getAccessKeyId();
        $accessKeySecret = $this->credential->getAccessKeySecret();
        // $host的格式为<YOUR-BUCKET>.<YOUR-ENDPOINT>'，请替换为您的真实信息。
        $host = !empty($this->config['endpoint_upload']) ? $this->config['endpoint_upload'] : $this->config['endpoint'];
        if (empty($host)) {
            $host = 'oss-cn-hangzhou.aliyuncs.com';
        }
        // 用户上传文件时指定的前缀。
        $dir = (!empty($this->path) ? $this->path . '/' : '') . date('Y-m-d') . '/' . Str::uuid()->getHex()->toString() . Str::random(4) . '-';

        //设置该policy超时时间是10s. 即这个policy过了这个有效时间，将不能访问。
        $expire = 30;
        $expiration = Carbon::now()->addSeconds($expire)->toIso8601ZuluString('millisecond');

        //最大文件大小.用户可以自己设置。
        $condition = [0 => 'content-length-range', 1 => 0, 2 => $maxSize];
        $conditions[] = $condition;

        // 表示用户上传的数据，必须是以$dir开始，不然上传会失败，这一步不是必须项，只是为了安全起见，防止用户通过policy上传到别人的目录。
        $start = [0 => 'starts-with', 1 => '$key', 2 => $dir];
        $conditions[] = $start;

        $arr = ['expiration' => $expiration, 'conditions' => $conditions];
        $policy = json_encode($arr);
        $base64Policy = base64_encode($policy);
        $string_to_sign = $base64Policy;
        $signature = base64_encode(hash_hmac('sha1', $string_to_sign, $accessKeySecret, true));

        return [
            'access_id' => $accessKeyId,
            'host' => $host,
            'policy' => $base64Policy,
            'signature' => $signature,
            'dir' => $dir,
            'bucket' => $this->config['bucket'],
        ];
    }

    public function signUploadV4($dir = '', $maxSize = 1048576000)
    {
        $accessKeyId = $this->credential->getAccessKeyId();
        $accessKeySecret = $this->credential->getAccessKeySecret();
        $securityToken = $this->credential->getSecurityToken();

        // $host的格式为<YOUR-BUCKET>.<YOUR-ENDPOINT>'，请替换为您的真实信息。
        $host = !empty($this->config['endpoint_upload']) ? $this->config['endpoint_upload'] : $this->config['endpoint'];
        if (empty($host)) {
            $host = 'oss-cn-hangzhou.aliyuncs.com';
        }
        // 用户上传文件时指定的前缀。
        $dir = (!empty($this->path) ? $this->path . '/' : '') . date('Y-m-d') . '/' . Str::uuid()->getHex()->toString() . Str::random(4) . '-';

        $now = Carbon::now('UTC');
        $date = $now->format('Ymd');
        $dateTime = $now->format('Ymd\THis\Z');
        $expire = 30;
        $expiration = $now->addSeconds($expire)->toIso8601ZuluString('microsecond');

        // bucket
        $conditions[] = ['bucket' => $this->bucket];
        // 签名版本
        $conditions[] = ['x-oss-signature-version' => 'OSS4-HMAC-SHA256'];
        // 凭证信息
        $xOssCredential = "{$accessKeyId}/{$date}/{$this->region}/oss/aliyun_v4_request";
        $conditions[] = ['x-oss-credential' => $xOssCredential];
        // 当前时间戳
        $conditions[] = ['x-oss-date' => $dateTime];
        //最大文件大小.用户可以自己设置。
        $conditions[] = [0 => 'content-length-range', 1 => 0, 2 => $maxSize];
        // 表示用户上传的数据，必须是以$dir开始，不然上传会失败，这一步不是必须项，只是为了安全起见，防止用户通过policy上传到别人的目录。
        $conditions[] = [0 => 'starts-with', 1 => '$key', 2 => $dir];
        // 其他条件 TODO
        if (!empty($securityToken)) {
            $conditions[] = ['x-oss-security-token' => $securityToken];
        }

        // 构建Policy
        $policy = [
            'expiration' => $expiration, // Policy的过期时间
            'conditions' => $conditions
        ];
        $policyStr = json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); // 防止转义斜杠和Unicode字符

        // 构造待签名字符串
        $stringToSign = base64_encode($policyStr);

        // 计算SigningKey
        $dateKey = $this->hmacsha256(('aliyun_v4' . $accessKeySecret), $date);
        $dateRegionKey = $this->hmacsha256($dateKey, $this->region);
        $dateRegionServiceKey = $this->hmacsha256($dateRegionKey, 'oss');
        $signingKey = $this->hmacsha256($dateRegionServiceKey, 'aliyun_v4_request');

        // 计算Signature
        $result = $this->hmacsha256($signingKey, $stringToSign);
        $signature = bin2hex($result);

        return [
            'policy' => $stringToSign, // Base64编码后的Policy
            'x_oss_signature_version' => "OSS4-HMAC-SHA256", // 签名版本
            'x_oss_credential' => $xOssCredential,
            'x_oss_date' => $dateTime,
            'signature' => $signature,
            'host' => $host,
            'dir' => $dir,
            'security_token' => $securityToken
        ];
    }

    /**
     * 前端直传，生成服务端签名地址
     *
     * @param  int  $maxSize 最大文件大小
     * @return array
     */
    public function signUrlUploadV4($fileName = '', $isPublicRead = false, $expires = 600)
    {
        if (empty($fileName)) {
            throw new \Exception('fileName 缺失');
        }
        if (!empty($expires) && $expires > 600) {
            throw new \Exception('签名过期时间最大允许 10 分钟有效');
        }
        // 获取 ak & sk 存在多种形式，目前采用静态配置获取默认 ak 和 sk TODO 后续可以考虑使用 sts
        $accessKeyId = $this->credential->getAccessKeyId();
        $accessKeySecret = $this->credential->getAccessKeySecret();
        $securityToken = $this->credential->getSecurityToken();
        $credentialsProvider = new StaticCredentialsProvider($accessKeyId, $accessKeySecret, $securityToken);
        $config = Config::loadDefault();
        $config->setCredentialsProvider($credentialsProvider);
        $config->setRegion($this->region); // 设置Bucket所在的地域

        // 用户上传文件时指定的前缀。
        $dir = (!empty($this->path) ? $this->path . '/' : '') . date('Y-m-d') . '/' . Str::uuid()->getHex()->toString() . Str::random(4) . '/';
        $file = $dir . $fileName;
        // 创建OSS客户端实例
        $client = new Client($config);

        $acl = ObjectACLType::DEFAULT;
        if ($isPublicRead) {
            $acl = ObjectACLType::PUBLIC_READ;
        }

        // 创建PutObjectRequest对象，用于上传对象
        $request = new PutObjectRequest(bucket: $this->bucket, key: $file, acl: $acl);

        $args = [
            'expires' => new DateInterval('PT' . $expires . 'S'),
        ];

        // 调用presign方法生成预签名URL
        $result = $client->presign($request, $args);
        return [
            'url' => $result->url,
        ];
    }

    // 计算HMAC-SHA256
    public function hmacsha256($key, $data) {
        return hash_hmac('sha256', $data, $key, true);
    }

    public function signUrl($object, $expire = 600)
    {
        try {
            $accessKeyId = $this->getCredential()->getAccessKeyId();
            $accessKeySecret = $this->getCredential()->getAccessKeySecret();
            $securityToken = $this->getCredential()->getSecurityToken();
            $credentialsProvider = new StaticCredentialsProvider($accessKeyId, $accessKeySecret, $securityToken);
            $config = Config::loadDefault();
            $config->setCredentialsProvider($credentialsProvider);
            $config->setRegion($this->region); // 设置Bucket所在的地域
            $client = new Client($config);

            // 创建GetObjectRequest对象，用于下载对象
            $request = new GetObjectRequest(bucket: $this->bucket, key: $object);
            // 调用presign方法生成预签名URL，设置过期时间
            $result = $client->presign($request, [
                'expires' => new \DateInterval("PT{$expire}S") // PT表示Period Time，S表示秒
            ]);
            return $result->url;
        } catch (\Exception $e) {
            Log::error('Oss signUrl Fail: ' . $e->getMessage());
            return '';
        }
    }
}
