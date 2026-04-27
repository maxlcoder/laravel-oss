<?php

namespace Maxlcoder\LaravelOss;

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

    public function __construct()
    {
        $this->config = config('oss');
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
        $accessKeyId = $this->config['access_key'];
        $accessKeySecret = $this->config['secret_key'];
        // $host的格式为<YOUR-BUCKET>.<YOUR-ENDPOINT>'，请替换为您的真实信息。
        $host = !empty($this->config['endpoint_upload']) ? $this->config['endpoint_upload'] : $this->config['endpoint'];
        if (empty($host)) {
            $host = 'oss-cn-hangzhou.aliyuncs.com';
        }
        // 用户上传文件时指定的前缀。
        $dir = (!empty($this->config['path']) ? $this->config['path'] . '/' : '') . date('Y-m-d') . '/' . Str::uuid()->getHex()->toString() . Str::random(4) . '-';

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
        $accessKeyId = $this->config['access_key'];
        $accessKeySecret = $this->config['secret_key'];
        $credentialsProvider = new StaticCredentialsProvider($accessKeyId, $accessKeySecret);
        $config = Config::loadDefault();
        $config->setCredentialsProvider($credentialsProvider);
        $config->setRegion($this->config['region']); // 设置Bucket所在的地域

        // 用户上传文件时指定的前缀。
        $dir = (!empty($this->config['path']) ? $this->config['path'] . '/' : '') . date('Y-m-d') . '/' . Str::uuid()->getHex()->toString() . Str::random(4) . '/';
        $file = $dir . $fileName;
        // 创建OSS客户端实例
        $client = new Client($config);

        $acl = ObjectACLType::DEFAULT;
        if ($isPublicRead) {
            $acl = ObjectACLType::PUBLIC_READ;
        }

        // 创建PutObjectRequest对象，用于上传对象
        $request = new PutObjectRequest(bucket: $this->config['bucket'], key: $file, acl: $acl);

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
            $accessKeyId = $this->config['access_key'];
            $accessKeySecret = $this->config['secret_key'];
            $credentialsProvider = new StaticCredentialsProvider($accessKeyId, $accessKeySecret);
            $config = Config::loadDefault();
            $config->setCredentialsProvider($credentialsProvider);
            $config->setRegion($this->config['region']); // 设置Bucket所在的地域
            $client = new Client($config);

            // 创建GetObjectRequest对象，用于下载对象
            $request = new GetObjectRequest(bucket: $this->config['bucket'], key: $object);
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


    public function signDownload($object, $timeout = 600)
    {
        try {
            $endpoint = $this->config['endpoint'] ?? 'https://oss-cn-hangzhou.aliyuncs.com';
            $ossClient = new OssClient($this->config['access_key'], $this->config['secret_key'], $endpoint, false);
            $bucket = $this->config['bucket'];
            $options = [
                'response-content-disposition' => 'attachment',
            ];
            return $ossClient->signUrl($bucket, urldecode($object), $timeout, 'GET', $options);
        } catch (\Exception $e) {
            Log::error('Oss signDownload Fail: ' . $e->getMessage());
            return '';
        }

    }


    // 后端上传文本图片
    public function uploadString($content, $fileName)
    {
        try {
            $provider = new StaticCredentialsProvider($this->config['access_key'], $this->config['secret_key']);
            $endpoint = $this->config['endpoint'] ?? 'https://oss-cn-hangzhou.aliyuncs.com';
            $ossClient = new OssClient([
                'provider' => $provider,
                'endpoint' => $endpoint,
                'signatureVersion' => OssClient::OSS_SIGNATURE_VERSION_V4,
                'region' => $this->config['region'] ?? 'cn-hangzhou',
            ]);
            $bucket = $this->config['bucket'];
            $dir = (!empty($this->config['path']) ? $this->config['path'] . '/' : '') . date('Y-m-d') . '/' . Str::uuid()->getHex()->toString() . Str::random(4) . '-';
            $object = $dir . $fileName;

            $result = $ossClient->putObject($bucket, $object, $content);
            if (empty($result)) {
                return '';
            }
            return [
                'bucket' => $bucket,
                'url' => $object,
                'content-md5' => $result['content-md5'],
            ];
        } catch (\Exception $e) {
            Log::error('Oss signDownload Fail: ' . $e->getMessage());
            return '';
        }
    }


}
