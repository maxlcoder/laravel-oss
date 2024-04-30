# laravel-oss


## 配置
配置文件在 `config/oss.php` 文件中，内容如下：

```php
return [
    'endpoint_upload' => env('OSS_ENDPOINT_UPLOAD'),
    'endpoint' => env('OSS_ENDPOINT'),
    'access_key' => env('OSS_ACCESS_KEY'),
    'secret_key' => env('OSS_SECRET_KEY'),
    'bucket' => env('OSS_BUCKET'),
    'path' => env('OSS_PATH', '')
];

```
**重点配置说明**

`endpoint_upload`: 表示和阿里云进行 API 交互的域名，形如 `oss-cn-hangzhou.aliyuncs.com`，例如文件上传，相关设置之类。注意这里如果是纯后端和 OSS 交互，且服务是部署在阿里云上，这里可以考虑使用内网域名  

`endpoint`: 文件域名通常作为文件下载的预览的地址域名，通常使用 bucket 域名，形如 `https://[buckent-name].oss-cn-hangzhou.aliyuncs.com`，如果是不想暴露 bucket 域名也可以使用自定义域名。

域名支持情况

| 域名                                                              | endpoint_upload 上行（API 交互） | endpoint 下行（文件下载预览） | 
|-----------------------------------------------------------------|----------------------------|---------------------|
| 地域域名（oss-cn-hangzhou.aliyuncs.com）                              | 支持                         | 不支持 |                 
| bucket 域名（https://[buckent-name].oss-cn-hangzhou.aliyuncs.com）  | 支持                         | 支持                  |
| 自定义域名（https://xxx.xxx.com）                                      | 支持                         | 支持                  | 

因此这里保留两个 endpoint 配置，便于支持各种情况

`path`: 考虑到单个 bucket 既作为开发环境，有作为生成环境，提供一个 bucket 下的顶层目录来区分不同环境下产生的文件。如果不同环境有不同的 bucket 或者不区分环境，该配置可以忽略 
