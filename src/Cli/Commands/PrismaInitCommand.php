<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;

/**
 * `php nesto prisma:init` — Prisma's `prisma init`: creates prisma/schema.prisma (datasource + the
 * Nesto client generator) and adds DATABASE_URL to .env when it is missing.
 */
final class PrismaInitCommand extends Command
{
    use InteractsWithPrisma;

    private const URLS = [
        'postgresql' => 'postgresql://johndoe:randompassword@localhost:5432/mydb?schema=public',
        'mysql' => 'mysql://johndoe:randompassword@localhost:3306/mydb',
        'mariadb' => 'mysql://johndoe:randompassword@localhost:3306/mydb',
        'sqlite' => 'file:./dev.db',
    ];

    protected string $signature = 'prisma:init {--datasource-provider=postgresql : postgresql, mysql, mariadb or sqlite} {--url= : The database URL to put in .env} {--output= : Generator output path}';
    protected string $description = 'Set up Prisma: create prisma/schema.prisma and DATABASE_URL in .env';
    protected string $help = <<<'TXT'
        Creates prisma/schema.prisma with a datasource and the "nesto-client" generator, and adds
        DATABASE_URL to the project's .env (created when missing). Existing files are not overwritten.

          {bin} prisma:init
          {bin} prisma:init --datasource-provider=mysql --url="mysql://root:secret@localhost:3306/app"
        TXT;

    public function handle(): int
    {
        $root = $this->context !== null ? $this->context->rootDir() : (string) getcwd();
        $provider = strtolower((string) $this->option('datasource-provider'));
        if (! isset(self::URLS[$provider])) {
            $this->error(sprintf('Provider "%s" is invalid or not supported. Try again with "postgresql", "mysql", "mariadb" or "sqlite".', $provider));

            return self::FAILURE;
        }
        $schemaFile = $root . '/prisma/schema.prisma';
        if (is_file($schemaFile)) {
            $this->error('File prisma/schema.prisma already exists in your project. Please try again in a project that is not yet using Prisma.');

            return self::FAILURE;
        }
        if (! is_dir(dirname($schemaFile))) {
            mkdir(dirname($schemaFile), 0777, true);
        }
        $output = $this->option('output');
        $generator = "generator client {\n  provider = \"nesto-client\"\n" . (is_string($output) && $output !== '' ? '  output   = "' . $output . "\"\n" : '') . "}\n";
        $schemaProvider = $provider === 'mariadb' ? 'mysql' : $provider;
        file_put_contents($schemaFile, "// This is your Prisma schema file,\n// learn more about it in the docs: https://pris.ly/d/prisma-schema\n\n"
            . $generator . "\ndatasource db {\n  provider = \"$schemaProvider\"\n  url      = env(\"DATABASE_URL\")\n}\n");
        $this->success('Your Prisma schema was created at prisma/schema.prisma');

        $envFile = $root . '/.env';
        $env = is_file($envFile) ? (string) file_get_contents($envFile) : '';
        if (preg_match('/^\s*(export\s+)?DATABASE_URL\s*=/m', $env) === 1) {
            $this->warn('Prisma would have added DATABASE_URL but it already exists in .env');
        } else {
            $url = $this->option('url');
            $url = is_string($url) && $url !== '' ? $url : self::URLS[$provider];
            $block = ($env !== '' && ! str_ends_with($env, "\n") ? "\n" : '') . ($env !== '' ? "\n" : '')
                . "# Environment variables declared in this file are automatically made available to Prisma.\n"
                . "# See the documentation for more detail: https://pris.ly/d/prisma-schema#accessing-environment-variables-from-the-schema\n\n"
                . "DATABASE_URL=\"$url\"\n";
            file_put_contents($envFile, $env . $block);
            $this->success(($env === '' ? 'Created .env with' : 'Added') . ' DATABASE_URL' . ($env === '' ? '' : ' to .env'));
        }
        $this->newLine();
        $this->line('Next steps:');
        $this->line('1. Set the DATABASE_URL in the .env file to point to your existing database.');
        $this->line('2. Define models in prisma/schema.prisma, then run `' . $this->bin() . ' prisma:migrate dev --name init` (or `prisma:db push`).');
        $this->line('3. Or run `' . $this->bin() . ' prisma:db pull` to turn your database schema into a Prisma schema.');

        return self::SUCCESS;
    }
}
