<?php

namespace Maxlcoder\LaravelOss;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OSS\OssClient;

class Oss
{
    protected $config;

    public function __construct()
    {
        $this->config = config('oss');
    }

    /**
     * 前端直传，生成服务端签名地址
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
        if (empty($dir)) {
            $dir = (!empty($this->config['path']) ? $this->config['path'] . '/' : '') . date('Y-m-d') . '/' . Str::uuid()->getHex()->toString() . Str::random(4) . '-';
        }

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
        ];
    }


    public function signUrl($object, $timeout = 600)
    {
        try {
            $endpoint = 'https://oss-cn-hangzhou.aliyuncs.com';
            $ossClient = new OssClient($this->config['access_key'], $this->config['secret_key'], $endpoint, false);
            $bucket = $this->config['bucket'];
            return $ossClient->signUrl($bucket, urldecode($object), $timeout, 'GET');
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


}