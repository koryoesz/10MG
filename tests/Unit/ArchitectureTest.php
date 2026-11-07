<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Enforces the Controller → Service → Repository layering:
 * controllers delegate, services hold rules, repositories own data access.
 */
class ArchitectureTest extends TestCase
{
    /**
     * Constructs that indicate business logic or data access.
     *
     * @var array<string, string>
     */
    private const CONTROLLER_FORBIDDEN = [
        'Eloquent query' => '/::query\(|::where\(|::find(OrFail)?\(|::create\(/',
        'transaction' => '/DB::/',
        // Persistence on a model or variable (delegating to `$this->someService->update()` is fine).
        'persistence' => '/\$(?!this\b)\w+->(save|delete|update|forceFill|fill)\(/',
        'row lock' => '/lockForUpdate/',
        'inline validation' => '/->validate\(/',
        'inline authorization' => '/Gate::|->authorize\(/',
        'password hashing' => '/Hash::/',
        'token handling' => '/createToken|tokens\(\)/',
        'repository access' => '/Repositories\\\\/',
        'audit writing' => '/AuditLogger/',
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function controllers(): array
    {
        return self::filesIn('app/Http/Controllers');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function services(): array
    {
        return self::filesIn('app/Services');
    }

    #[DataProvider('controllers')]
    public function test_controllers_contain_no_business_logic_or_data_access(string $path): void
    {
        $source = file_get_contents($path);

        foreach (self::CONTROLLER_FORBIDDEN as $concern => $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, $source, "{$path} contains {$concern}; move it into a service.");
        }
    }

    #[DataProvider('services')]
    public function test_services_reach_the_database_only_through_repositories(string $path): void
    {
        $source = file_get_contents($path);

        $this->assertDoesNotMatchRegularExpression(
            '/[A-Z][A-Za-z]+::(query|where|find|findOrFail|create|whereKey)\(/',
            $source,
            "{$path} queries Eloquent directly; add a repository method instead.",
        );
        $this->assertDoesNotMatchRegularExpression('/lockForUpdate\(\)/', $source, "{$path} locks rows directly; use a repository.");
    }

    public function test_every_repository_contract_has_an_eloquent_implementation(): void
    {
        foreach (glob(dirname(__DIR__, 2).'/app/Repositories/Contracts/*RepositoryInterface.php') as $contract) {
            $implementation = str_replace(['Contracts', 'RepositoryInterface'], ['Eloquent', 'Repository'], $contract);

            $this->assertFileExists($implementation);
            $this->assertStringContainsString('implements '.basename($contract, '.php'), file_get_contents($implementation));
        }
    }

    /**
     * @return array<string, array{string}>
     */
    private static function filesIn(string $directory): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}")) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && $file->getFilename() !== 'Controller.php') {
                $files[substr($file->getPathname(), strlen($root) + 1)] = [$file->getPathname()];
            }
        }

        ksort($files);

        return $files;
    }
}
