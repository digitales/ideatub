<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateOAuthMcpKeysCommand extends Command
{
    protected $signature = 'ideatub:oauth-mcp-keys
                            {--force : Overwrite existing keys}';

    protected $description = 'Generate RSA key pair for OAuth MCP (ChatGPT connector) JWT signing.';

    public function handle(): int
    {
        $privatePath = config('oauth-mcp.private_key_path');
        $publicPath = config('oauth-mcp.public_key_path');

        if (File::exists($privatePath) && ! $this->option('force')) {
            $this->warn('Private key already exists. Use --force to overwrite.');

            return self::FAILURE;
        }

        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $key = openssl_pkey_new($config);
        if ($key === false) {
            $this->error('Failed to generate key: '.openssl_error_string());

            return self::FAILURE;
        }

        $privatePem = null;
        if (! openssl_pkey_export($key, $privatePem)) {
            $this->error('Failed to export private key.');

            return self::FAILURE;
        }

        $details = openssl_pkey_get_details($key);
        if (! $details || ! isset($details['key'])) {
            $this->error('Failed to get public key.');

            return self::FAILURE;
        }

        $publicPem = $details['key'];

        File::ensureDirectoryExists(dirname($privatePath));
        File::put($privatePath, $privatePem);
        File::put($publicPath, $publicPem);

        // Restrict private key to owner read only when possible
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($privatePath, 0600);
        }

        $this->info('OAuth MCP keys generated:');
        $this->line('  Private: '.$privatePath);
        $this->line('  Public:  '.$publicPath);
        $this->newLine();
        $this->warn('Keep the private key secret. Add storage/app/oauth-mcp-*.pem to .gitignore if not already.');

        return self::SUCCESS;
    }
}
