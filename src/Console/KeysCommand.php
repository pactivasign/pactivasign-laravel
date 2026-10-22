<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use PactivaSign\Key\LocalKey;

final class KeysCommand extends Command
{
    protected $signature = 'pactivasign:keys
        {--dir= : Where to write the keys (default: storage/pactivasign)}
        {--algorithm=ES256 : ES256, ES384, ES512, PS256, RS256 or EdDSA}
        {--kid= : Key ID for the client key (default: <app-name>-<date>)}
        {--force : Overwrite existing key files}';

    protected $description = 'Generate the PactivaSign client key and DPoP key, and print the public key to register';

    public function handle(): int
    {
        $dir = is_string($this->option('dir')) && $this->option('dir') !== '' ? $this->option('dir') : storage_path('pactivasign');
        $clientPath = $dir . '/client.jwk';
        $dpopPath = $dir . '/dpop.jwk';
        foreach ([$clientPath, $dpopPath] as $path) {
            if (is_file($path) && !$this->option('force')) {
                $this->error("$path already exists. Rotating keys? Register the new client key first, then use --force.");

                return self::FAILURE;
            }
        }
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            $this->error("Cannot create $dir.");

            return self::FAILURE;
        }

        $algorithm = is_string($this->option('algorithm')) ? $this->option('algorithm') : 'ES256';
        $kid = is_string($this->option('kid')) && $this->option('kid') !== ''
            ? $this->option('kid')
            : Str::slug(self::appName($this->laravel->make('config')->get('app.name'))) . '-' . date('Y-m-d');

        $clientKey = LocalKey::generate($algorithm, $kid);
        $dpopKey = LocalKey::generate('ES256');
        foreach ([$clientPath => $clientKey, $dpopPath => $dpopKey] as $path => $key) {
            touch($path);
            chmod($path, 0o600);
            file_put_contents($path, $key->exportPrivateJwk());
        }

        $this->info('Wrote ' . $clientPath . ' and ' . $dpopPath . ' (mode 0600). Keep them out of version control.');
        $this->newLine();
        $this->line('Ask a PactivaSign organization admin to register this public key for your OAuth client:');
        $this->newLine();
        $this->line((string) json_encode($clientKey->registrationJwk(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();
        $this->line('Then set PACTIVASIGN_CLIENT_ID in .env. The DPoP key is never registered, but must stay the same on every server.');

        return self::SUCCESS;
    }

    private static function appName(mixed $name): string
    {
        return is_string($name) && $name !== '' ? $name : 'app';
    }
}
