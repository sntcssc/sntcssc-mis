<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class StorageService
{
    /** Supported cloud providers. */
    public const PROVIDER_AWS = 'aws';

    public const PROVIDER_R2 = 'r2';

    public const PROVIDER_MINIO = 'minio';

    public const PROVIDER_WASABI = 'wasabi';

    public const PROVIDER_DO_SPACES = 'do_spaces';

    public const PROVIDER_BACKBLAZE = 'backblaze';

    public const PROVIDER_CUSTOM = 'custom';

    /**
     * Provider presets containing recommended defaults for various S3-compatible cloud providers.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getProviderPresets(): array
    {
        return [
            self::PROVIDER_AWS => [
                'name' => 'Amazon Web Services (AWS S3)',
                'icon' => 'cloud',
                'description' => 'Enterprise-grade cloud object storage with global multi-region support and CloudFront CDN integration.',
                'default_region' => 'us-east-1',
                'endpoint_placeholder' => 'https://s3.amazonaws.com (leave blank for default AWS endpoints)',
                'url_placeholder' => 'https://bucket-name.s3.amazonaws.com or https://cdn.yourdomain.com',
                'path_style' => false,
                'regions' => [
                    'us-east-1' => 'US East (N. Virginia)',
                    'us-east-2' => 'US East (Ohio)',
                    'us-west-1' => 'US West (N. California)',
                    'us-west-2' => 'US West (Oregon)',
                    'ap-south-1' => 'Asia Pacific (Mumbai)',
                    'ap-south-2' => 'Asia Pacific (Hyderabad)',
                    'ap-southeast-1' => 'Asia Pacific (Singapore)',
                    'ap-northeast-1' => 'Asia Pacific (Tokyo)',
                    'eu-central-1' => 'Europe (Frankfurt)',
                    'eu-west-1' => 'Europe (Ireland)',
                    'me-south-1' => 'Middle East (Bahrain)',
                ],
            ],
            self::PROVIDER_R2 => [
                'name' => 'Cloudflare R2',
                'icon' => 'cloud-upload',
                'description' => 'Fast, scalable S3-compatible object storage with ZERO data egress bandwidth fees.',
                'default_region' => 'auto',
                'endpoint_placeholder' => 'https://<ACCOUNT_ID>.r2.cloudflarestorage.com',
                'url_placeholder' => 'https://pub-<hash>.r2.dev or https://assets.yourdomain.com',
                'path_style' => false,
                'regions' => [
                    'auto' => 'Auto (Automatic Geographic Routing)',
                    'wnam' => 'Western North America',
                    'enam' => 'Eastern North America',
                    'weur' => 'Western Europe',
                    'eeur' => 'Eastern Europe',
                    'apac' => 'Asia-Pacific',
                ],
            ],
            self::PROVIDER_MINIO => [
                'name' => 'MinIO (Self-Hosted S3)',
                'icon' => 'server',
                'description' => 'High-performance S3-compatible object storage suite running on private bare-metal or Docker infrastructure.',
                'default_region' => 'us-east-1',
                'endpoint_placeholder' => 'http://localhost:9000 or https://minio.yourdomain.com',
                'url_placeholder' => 'http://localhost:9000/bucket-name',
                'path_style' => true,
                'regions' => [
                    'us-east-1' => 'Default (us-east-1)',
                    'local' => 'Local Cluster',
                ],
            ],
            self::PROVIDER_WASABI => [
                'name' => 'Wasabi Hot Cloud Storage',
                'icon' => 'hard-drive',
                'description' => 'Low-cost, high-speed S3-compliant object storage with no charges for egress or API requests.',
                'default_region' => 'us-east-1',
                'endpoint_placeholder' => 'https://s3.wasabisys.com (or https://s3.ap-northeast-1.wasabisys.com)',
                'url_placeholder' => 'https://s3.wasabisys.com/bucket-name',
                'path_style' => false,
                'regions' => [
                    'us-east-1' => 'US East 1 (N. Virginia)',
                    'us-east-2' => 'US East 2 (Manassas)',
                    'us-west-1' => 'US West 1 (Oregon)',
                    'eu-central-1' => 'Europe Central 1 (Amsterdam)',
                    'ap-northeast-1' => 'AP Northeast 1 (Tokyo)',
                ],
            ],
            self::PROVIDER_DO_SPACES => [
                'name' => 'DigitalOcean Spaces',
                'icon' => 'server-cog',
                'description' => 'Predictable, developer-friendly object storage with built-in CDN edge distribution.',
                'default_region' => 'nyc3',
                'endpoint_placeholder' => 'https://nyc3.digitaloceanspaces.com',
                'url_placeholder' => 'https://bucket-name.nyc3.cdn.digitaloceanspaces.com',
                'path_style' => false,
                'regions' => [
                    'nyc3' => 'New York (NYC3)',
                    'ams3' => 'Amsterdam (AMS3)',
                    'sfo3' => 'San Francisco (SFO3)',
                    'sgp1' => 'Singapore (SGP1)',
                    'blr1' => 'Bangalore (BLR1)',
                    'fra1' => 'Frankfurt (FRA1)',
                ],
            ],
            self::PROVIDER_BACKBLAZE => [
                'name' => 'Backblaze B2',
                'icon' => 'database',
                'description' => 'Affordable, reliable S3-compatible cloud storage with high durability and fast retrieval.',
                'default_region' => 'us-west-002',
                'endpoint_placeholder' => 'https://s3.us-west-002.backblazeb2.com',
                'url_placeholder' => 'https://f002.backblazeb2.com/file/bucket-name',
                'path_style' => false,
                'regions' => [
                    'us-west-002' => 'US West (002)',
                    'us-east-005' => 'US East (005)',
                    'eu-central-003' => 'Europe Central (003)',
                ],
            ],
            self::PROVIDER_CUSTOM => [
                'name' => 'Custom S3-Compatible',
                'icon' => 'hard-drive',
                'description' => 'Any standard S3-compliant object store (Ceph, Scaleway, Alibaba OSS, Huawei OBS, Synology C2, etc.).',
                'default_region' => 'us-east-1',
                'endpoint_placeholder' => 'https://s3.yourprovider.com',
                'url_placeholder' => 'https://cdn.yourdomain.com',
                'path_style' => false,
                'regions' => [
                    'us-east-1' => 'Default (us-east-1)',
                    'auto' => 'Auto',
                    'custom' => 'Custom Region',
                ],
            ],
        ];
    }

    /**
     * Apply active storage configuration settings to runtime Laravel filesystem configs.
     */
    public static function applyDynamicConfig(): void
    {
        try {
            $defaultDisk = Setting::get('storage.default_disk');
            if ($defaultDisk && in_array($defaultDisk, ['public', 's3', 'local'], true)) {
                config(['filesystems.default' => $defaultDisk]);
            }

            $s3Key = Setting::get('storage.s3_key');
            $s3Secret = Setting::get('storage.s3_secret');
            $s3Region = Setting::get('storage.s3_region') ?: config('filesystems.disks.s3.region', 'us-east-1');
            $s3Bucket = Setting::get('storage.s3_bucket');
            $s3Endpoint = Setting::get('storage.s3_endpoint');
            $s3Url = Setting::get('storage.s3_url');
            $s3UsePathStyle = Setting::get('storage.s3_use_path_style_endpoint');

            if ($s3Key || $s3Bucket || $s3Region || $s3Endpoint) {
                config([
                    'filesystems.disks.s3.key' => $s3Key ?: config('filesystems.disks.s3.key'),
                    'filesystems.disks.s3.secret' => $s3Secret ?: config('filesystems.disks.s3.secret'),
                    'filesystems.disks.s3.region' => $s3Region ?: 'us-east-1',
                    'filesystems.disks.s3.bucket' => $s3Bucket ?: config('filesystems.disks.s3.bucket'),
                    'filesystems.disks.s3.endpoint' => $s3Endpoint ?: config('filesystems.disks.s3.endpoint'),
                    'filesystems.disks.s3.url' => $s3Url ?: config('filesystems.disks.s3.url'),
                    'filesystems.disks.s3.use_path_style_endpoint' => $s3UsePathStyle !== null ? (bool) $s3UsePathStyle : (bool) config('filesystems.disks.s3.use_path_style_endpoint', false),
                    'filesystems.disks.s3.throw' => false,
                ]);

                // Clear cached disk instance so changes take effect immediately
                self::forgetDiskCache('s3');
            }
        } catch (Throwable $e) {
            Log::warning('StorageService::applyDynamicConfig failed: '.$e->getMessage());
        }
    }

    /**
     * Invalidate cached filesystem disk instance in the application container.
     */
    public static function forgetDiskCache(string $disk = 's3'): void
    {
        try {
            if (app()->bound('filesystem')) {
                app('filesystem')->forgetDisk($disk);
            }
        } catch (Throwable) {
            // Ignore during early bootstrap
        }
    }

    /**
     * Test connection to Amazon S3 or S3-compatible cloud storage.
     * Writes, reads, verifies, and deletes a probe file to guarantee full read/write operational capability.
     *
     * @param  array<string, mixed>  $overrides  Optional credential/config overrides to test unsaved values
     * @return array{
     *     success: bool,
     *     message: string,
     *     latency_ms: float,
     *     details: array<string, mixed>,
     *     probe_url: ?string
     * }
     */
    public static function testConnection(array $overrides = []): array
    {
        $startTime = microtime(true);
        $probeId = Str::random(12);
        $probeFilename = "__probe_snt_s3_{$probeId}.txt";
        $probeContent = 'SNT CSSC MIS Storage Connection Diagnostic Probe - Timestamp: '.now()->toIso8601String().' - Random: '.Str::random(32);
        $tempDiskName = 's3_test_'.Str::random(6);

        // Prepare config from overrides or runtime config
        $key = $overrides['s3_key'] ?? Setting::get('storage.s3_key') ?? config('filesystems.disks.s3.key');
        $secret = $overrides['s3_secret'] ?? Setting::get('storage.s3_secret') ?? config('filesystems.disks.s3.secret');
        $region = $overrides['s3_region'] ?? Setting::get('storage.s3_region') ?? config('filesystems.disks.s3.region', 'us-east-1');
        $bucket = $overrides['s3_bucket'] ?? Setting::get('storage.s3_bucket') ?? config('filesystems.disks.s3.bucket');
        $endpoint = $overrides['s3_endpoint'] ?? Setting::get('storage.s3_endpoint') ?? config('filesystems.disks.s3.endpoint');
        $url = $overrides['s3_url'] ?? Setting::get('storage.s3_url') ?? config('filesystems.disks.s3.url');
        $usePathStyle = $overrides['s3_use_path_style_endpoint'] ?? Setting::get('storage.s3_use_path_style_endpoint') ?? config('filesystems.disks.s3.use_path_style_endpoint', false);

        if (empty($key)) {
            return [
                'success' => false,
                'message' => __('Missing Access Key ID. Please provide valid AWS / S3 access credentials.'),
                'latency_ms' => 0.0,
                'details' => ['error_code' => 'MISSING_ACCESS_KEY'],
                'probe_url' => null,
            ];
        }

        if (empty($secret)) {
            return [
                'success' => false,
                'message' => __('Missing Secret Access Key. Please provide valid AWS / S3 secret credentials.'),
                'latency_ms' => 0.0,
                'details' => ['error_code' => 'MISSING_SECRET_KEY'],
                'probe_url' => null,
            ];
        }

        if (empty($bucket)) {
            return [
                'success' => false,
                'message' => __('Missing Bucket Name. Please specify the target S3 bucket name.'),
                'latency_ms' => 0.0,
                'details' => ['error_code' => 'MISSING_BUCKET_NAME'],
                'probe_url' => null,
            ];
        }

        // Configure temporary disk for safe isolated testing
        config([
            "filesystems.disks.{$tempDiskName}" => [
                'driver' => 's3',
                'key' => (string) $key,
                'secret' => (string) $secret,
                'region' => (string) ($region ?: 'us-east-1'),
                'bucket' => (string) $bucket,
                'endpoint' => $endpoint ?: null,
                'url' => $url ?: null,
                'use_path_style_endpoint' => (bool) $usePathStyle,
                'throw' => true,
            ],
        ]);

        $details = [
            'provider' => $overrides['provider'] ?? Setting::get('storage.provider', 'aws'),
            'bucket' => $bucket,
            'region' => $region ?: 'us-east-1',
            'endpoint' => $endpoint ?: 'AWS Default Endpoint',
            'path_style' => (bool) $usePathStyle,
            'steps_completed' => [],
        ];

        try {
            $disk = Storage::disk($tempDiskName);

            // Step 1: Write Probe
            $disk->put($probeFilename, $probeContent);
            $details['steps_completed'][] = 'probe_written';

            // Step 2: Verify Existence
            if (! $disk->exists($probeFilename)) {
                throw new \RuntimeException(__('Probe file written but existence check failed.'));
            }
            $details['steps_completed'][] = 'probe_verified';

            // Step 3: Read back and match content
            $readBack = $disk->get($probeFilename);
            if ($readBack !== $probeContent) {
                throw new \RuntimeException(__('Data integrity check failed: read content did not match written content.'));
            }
            $details['steps_completed'][] = 'data_integrity_verified';

            // Step 4: Resolve public / CDN URL
            $probeUrl = null;
            try {
                $probeUrl = $disk->url($probeFilename);
                $details['steps_completed'][] = 'url_resolved';
            } catch (Throwable $urlErr) {
                Log::notice('Storage probe URL generation warning: '.$urlErr->getMessage());
            }

            // Step 5: Clean up probe file
            $disk->delete($probeFilename);
            $details['steps_completed'][] = 'probe_cleaned_up';

            $latency = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'success' => true,
                'message' => __("Successfully connected to S3 bucket ':bucket' in :region (:latency ms). Read, write, and delete operations verified.", [
                    'bucket' => $bucket,
                    'region' => $region ?: 'us-east-1',
                    'latency' => $latency,
                ]),
                'latency_ms' => $latency,
                'details' => $details,
                'probe_url' => $probeUrl,
            ];
        } catch (Throwable $e) {
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            $errorMsg = $e->getMessage();
            $diagnostics = self::diagnoseS3Error($errorMsg, (string) $endpoint, (bool) $usePathStyle);

            Log::error('StorageService S3 connection test failed: '.$errorMsg, [
                'exception' => $e,
                'details' => $details,
            ]);

            return [
                'success' => false,
                'message' => $diagnostics['summary'] ?? __('Connection test failed: :error', ['error' => $errorMsg]),
                'latency_ms' => $latency,
                'details' => array_merge($details, [
                    'raw_error' => $errorMsg,
                    'diagnostic_tip' => $diagnostics['tip'] ?? null,
                ]),
                'probe_url' => null,
            ];
        } finally {
            // Clean up temporary disk config
            config(["filesystems.disks.{$tempDiskName}" => null]);
            self::forgetDiskCache($tempDiskName);
        }
    }

    /**
     * Diagnose common S3 connection errors and return clear troubleshooting guidance.
     *
     * @return array{summary: string, tip: string}
     */
    protected static function diagnoseS3Error(string $rawError, string $endpoint, bool $pathStyle): array
    {
        if (str_contains($rawError, 'AccessDenied') || str_contains($rawError, 'InvalidAccessKeyId')) {
            return [
                'summary' => __('Access Denied: Authentication failed. Please verify your Access Key ID and Secret Access Key.'),
                'tip' => __('Ensure your IAM user or API token has s3:PutObject, s3:GetObject, s3:DeleteObject, and s3:ListBucket permissions on the specified bucket.'),
            ];
        }

        if (str_contains($rawError, 'NoSuchBucket')) {
            return [
                'summary' => __('Bucket Not Found: The specified bucket does not exist or is in a different region.'),
                'tip' => __('Double check the bucket name and ensure the region setting matches where the bucket was created in your cloud console.'),
            ];
        }

        if (str_contains($rawError, 'SignatureDoesNotMatch')) {
            return [
                'summary' => __('Signature Mismatch: The request signature does not match. Secret Key may be incorrect or contain special characters.'),
                'tip' => __('Carefully re-enter your Secret Access Key. Ensure no leading or trailing whitespace is present.'),
            ];
        }

        if (str_contains($rawError, 'Could not resolve host') || str_contains($rawError, 'cURL error 6')) {
            return [
                'summary' => __('Network Resolution Error: Hostname could not be resolved.'),
                'tip' => __('Check your Endpoint URL and network/DNS settings. If using MinIO or local storage, ensure the container or server is online.'),
            ];
        }

        if (str_contains($rawError, 'Connection refused') || str_contains($rawError, 'cURL error 7')) {
            return [
                'summary' => __('Connection Refused: Target storage endpoint refused connection.'),
                'tip' => __('Verify the endpoint port (e.g. :9000 for MinIO) and check firewall/security group rules.'),
            ];
        }

        if (str_contains($rawError, 'SSL certificate') || str_contains($rawError, 'cURL error 60')) {
            return [
                'summary' => __('SSL Certificate Error: Storage endpoint SSL certificate could not be validated.'),
                'tip' => __('If using local MinIO over HTTP, make sure the endpoint URL specifies http:// rather than https://.'),
            ];
        }

        return [
            'summary' => __('Storage connection failed: ').Str::limit($rawError, 180),
            'tip' => __('Check credentials, bucket permissions, region, and endpoint configuration.'),
        ];
    }

    /**
     * Gather comprehensive real-time storage statistics across local disks and S3.
     *
     * @return array<string, mixed>
     */
    public static function getStorageStatistics(): array
    {
        $defaultDisk = (string) Setting::get('storage.default_disk', config('filesystems.default', 'public'));
        $provider = (string) Setting::get('storage.provider', 'aws');
        $bucket = (string) (Setting::get('storage.s3_bucket') ?: config('filesystems.disks.s3.bucket'));
        $region = (string) (Setting::get('storage.s3_region') ?: config('filesystems.disks.s3.region', 'us-east-1'));
        $hasS3Config = ! empty($bucket) && ! empty(Setting::get('storage.s3_key') ?: config('filesystems.disks.s3.key'));

        $publicPath = storage_path('app/public');
        $privatePath = storage_path('app/private');

        $publicStats = self::scanDirectoryStats($publicPath);
        $privateStats = self::scanDirectoryStats($privatePath);

        $maxUploadMb = (int) Setting::get('storage.max_upload_size_mb', 25);
        $allowedTypes = (string) Setting::get('storage.allowed_file_types', 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,svg,zip,mp4');

        return [
            'default_disk' => $defaultDisk,
            'provider' => $provider,
            'provider_name' => self::getProviderPresets()[$provider]['name'] ?? ucfirst($provider),
            's3_configured' => $hasS3Config,
            's3_bucket' => $bucket,
            's3_region' => $region,
            's3_status' => $hasS3Config ? ($defaultDisk === 's3' ? 'active' : 'standby') : 'not_configured',
            'public_storage' => [
                'size_bytes' => $publicStats['total_size'],
                'size_formatted' => FileUploadService::humanSize($publicStats['total_size']),
                'files_count' => $publicStats['total_files'],
                'categories' => $publicStats['categories'],
            ],
            'private_storage' => [
                'size_bytes' => $privateStats['total_size'],
                'size_formatted' => FileUploadService::humanSize($privateStats['total_size']),
                'files_count' => $privateStats['total_files'],
            ],
            'total_files' => $publicStats['total_files'] + $privateStats['total_files'],
            'total_size_bytes' => $publicStats['total_size'] + $privateStats['total_size'],
            'total_size_formatted' => FileUploadService::humanSize($publicStats['total_size'] + $privateStats['total_size']),
            'policy' => [
                'max_upload_mb' => $maxUploadMb,
                'allowed_extensions' => array_map('trim', explode(',', $allowedTypes)),
                'visibility' => (string) Setting::get('storage.s3_visibility', 'public'),
                'auto_backup_to_s3' => (bool) Setting::get('storage.auto_backup_to_s3', false),
            ],
        ];
    }

    /**
     * Scan a directory and calculate size, file counts, and categories breakdown.
     *
     * @return array{total_size: int, total_files: int, categories: array<string, array{count: int, size: int, size_formatted: string}>}
     */
    protected static function scanDirectoryStats(string $directory): array
    {
        $result = [
            'total_size' => 0,
            'total_files' => 0,
            'categories' => [
                'documents' => ['count' => 0, 'size' => 0, 'size_formatted' => '0 B'],
                'images' => ['count' => 0, 'size' => 0, 'size_formatted' => '0 B'],
                'archives' => ['count' => 0, 'size' => 0, 'size_formatted' => '0 B'],
                'other' => ['count' => 0, 'size' => 0, 'size_formatted' => '0 B'],
            ],
        ];

        if (! File::exists($directory)) {
            return $result;
        }

        try {
            $files = File::allFiles($directory);

            foreach ($files as $file) {
                $size = $file->getSize();
                $ext = strtolower($file->getExtension());

                $result['total_size'] += $size;
                $result['total_files']++;

                if (in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv'], true)) {
                    $cat = 'documents';
                } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'bmp', 'ico'], true)) {
                    $cat = 'images';
                } elseif (in_array($ext, ['zip', 'tar', 'gz', 'rar', '7z', 'sql'], true)) {
                    $cat = 'archives';
                } else {
                    $cat = 'other';
                }

                $result['categories'][$cat]['count']++;
                $result['categories'][$cat]['size'] += $size;
            }

            foreach ($result['categories'] as $cat => $data) {
                $result['categories'][$cat]['size_formatted'] = FileUploadService::humanSize($data['size']);
            }
        } catch (Throwable $e) {
            Log::warning('Storage scanDirectoryStats error: '.$e->getMessage());
        }

        return $result;
    }

    /**
     * List recent files on the specified disk (e.g. 'public' or 's3').
     *
     * @return array<int, array{
     *     name: string,
     *     path: string,
     *     size: int,
     *     size_formatted: string,
     *     last_modified: string,
     *     url: ?string,
     *     extension: string,
     *     is_image: bool
     * }>
     */
    public static function listFiles(string $disk = 'public', string $directory = '', int $limit = 40): array
    {
        $items = [];

        try {
            if ($disk === 's3' && ! Setting::get('storage.s3_bucket') && ! config('filesystems.disks.s3.bucket')) {
                return [];
            }

            $storageDisk = Storage::disk($disk);
            $files = $storageDisk->allFiles($directory);

            // Sort files descending by last modified
            $fileDetails = [];
            foreach ($files as $filePath) {
                // Ignore probe files and hidden system files
                if (str_contains($filePath, '__probe_') || basename($filePath)[0] === '.') {
                    continue;
                }

                $lastModified = 0;
                $size = 0;

                try {
                    $lastModified = $storageDisk->lastModified($filePath);
                    $size = $storageDisk->size($filePath);
                } catch (Throwable) {
                    // Ignore metadata failures on certain remote drivers
                }

                $fileDetails[] = [
                    'path' => $filePath,
                    'last_modified_timestamp' => $lastModified,
                    'size' => $size,
                ];
            }

            usort($fileDetails, fn ($a, $b) => $b['last_modified_timestamp'] <=> $a['last_modified_timestamp']);
            $fileDetails = array_slice($fileDetails, 0, $limit);

            foreach ($fileDetails as $item) {
                $path = $item['path'];
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true);

                $url = null;
                try {
                    $url = $storageDisk->url($path);
                } catch (Throwable) {
                    $url = null;
                }

                $items[] = [
                    'name' => basename($path),
                    'path' => $path,
                    'size' => $item['size'],
                    'size_formatted' => FileUploadService::humanSize($item['size']),
                    'last_modified' => $item['last_modified_timestamp'] ? date('Y-m-d H:i:s', $item['last_modified_timestamp']) : '-',
                    'url' => $url,
                    'extension' => $ext,
                    'is_image' => $isImage,
                ];
            }
        } catch (Throwable $e) {
            Log::warning("StorageService::listFiles failed for disk '{$disk}': ".$e->getMessage());
        }

        return $items;
    }

    /**
     * Upload an arbitrary file to the configured storage disk with security validations.
     *
     * @return array{path: string, url: ?string, disk: string, size: int}
     *
     * @throws \RuntimeException
     */
    public static function uploadFile(
        UploadedFile|TemporaryUploadedFile $file,
        string $folder = 'uploads',
        ?string $disk = null,
        ?User $actor = null
    ): array {
        $maxMb = (int) Setting::get('storage.max_upload_size_mb', 25);
        $maxBytes = $maxMb * 1024 * 1024;

        if ($file->getSize() > $maxBytes) {
            throw new \RuntimeException(__('File exceeds maximum allowed upload size of :max MB.', ['max' => $maxMb]));
        }

        $allowedExts = array_map('trim', explode(',', (string) Setting::get('storage.allowed_file_types', 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,svg,zip,mp4')));
        $extension = strtolower($file->getClientOriginalExtension());

        if (! empty($allowedExts) && ! in_array($extension, $allowedExts, true)) {
            throw new \RuntimeException(__("File extension ':ext' is not permitted.", ['ext' => $extension]));
        }

        $targetDisk = $disk ?? (string) Setting::get('storage.default_disk', 'public');

        // Fallback to public if S3 is requested but unconfigured
        if ($targetDisk === 's3' && ! Setting::get('storage.s3_bucket') && ! config('filesystems.disks.s3.bucket')) {
            $targetDisk = 'public';
        }

        $path = FileUploadService::store($file, $folder, 'upload', $targetDisk);
        $url = FileUploadService::url($path, $targetDisk);

        AuditLogService::log(
            event: 'storage_file_uploaded',
            description: "Uploaded file '{$file->getClientOriginalName()}' to {$targetDisk}:{$path}.",
            newValues: [
                'original_name' => $file->getClientOriginalName(),
                'size_bytes' => $file->getSize(),
                'disk' => $targetDisk,
                'path' => $path,
                'actor_id' => $actor?->id ?? auth()->id(),
            ],
            userId: $actor?->id ?? auth()->id()
        );

        return [
            'path' => $path,
            'url' => $url,
            'disk' => $targetDisk,
            'size' => $file->getSize(),
        ];
    }

    /**
     * Safely delete a file from storage with audit trail.
     */
    public static function deleteFile(string $path, string $disk = 'public', ?User $actor = null): bool
    {
        try {
            $storageDisk = Storage::disk($disk);

            if (! $storageDisk->exists($path)) {
                return false;
            }

            $deleted = $storageDisk->delete($path);

            if ($deleted) {
                AuditLogService::log(
                    event: 'storage_file_deleted',
                    description: "Deleted file '{$path}' from storage disk '{$disk}'.",
                    newValues: [
                        'disk' => $disk,
                        'path' => $path,
                        'actor_id' => $actor?->id ?? auth()->id(),
                    ],
                    userId: $actor?->id ?? auth()->id()
                );
            }

            return $deleted;
        } catch (Throwable $e) {
            Log::error("Failed to delete file from {$disk}: {$path} - ".$e->getMessage());

            return false;
        }
    }

    /**
     * Generate a pre-signed direct upload URL for S3/compatible cloud storage.
     *
     * @return array{upload_url: string, method: string, headers: array<string, string>, path: string, expires_at: string}|null
     */
    public static function createPresignedUploadUrl(string $path, string $contentType = 'application/octet-stream', int $expiryMinutes = 15): ?array
    {
        try {
            self::applyDynamicConfig();
            $disk = Storage::disk('s3');

            if (method_exists($disk, 'temporaryUploadUrl')) {
                $expiresAt = now()->addMinutes($expiryMinutes);
                $url = $disk->temporaryUploadUrl($path, $expiresAt);

                return [
                    'upload_url' => $url,
                    'method' => 'PUT',
                    'headers' => [
                        'Content-Type' => $contentType,
                    ],
                    'path' => $path,
                    'expires_at' => $expiresAt->toIso8601String(),
                ];
            }
        } catch (Throwable $e) {
            Log::warning('StorageService::createPresignedUploadUrl failed: '.$e->getMessage());
        }

        return null;
    }
}
