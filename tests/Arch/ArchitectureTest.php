<?php

declare(strict_types=1);

// --- Strict types ---

it('enforces strict types across the package', function (): void {
    expect('DynamikDev\PolicyEngine')
        ->toUseStrictTypes();
});

it('enforces strict types in tests', function (): void {
    expect('Tests')
        ->toUseStrictTypes();
});

// --- Namespace structure: every class must live in an approved namespace ---

it('only allows known namespaces', function (): void {
    $allowed = [
        'DynamikDev\PolicyEngine\Attributes',
        'DynamikDev\PolicyEngine\Commands',
        'DynamikDev\PolicyEngine\Concerns',
        'DynamikDev\PolicyEngine\Conditions',
        'DynamikDev\PolicyEngine\Contracts',
        'DynamikDev\PolicyEngine\Documents',
        'DynamikDev\PolicyEngine\DTOs',
        'DynamikDev\PolicyEngine\Enums',
        'DynamikDev\PolicyEngine\Evaluators',
        'DynamikDev\PolicyEngine\Events',
        'DynamikDev\PolicyEngine\Facades',
        'DynamikDev\PolicyEngine\Listeners',
        'DynamikDev\PolicyEngine\Matchers',
        'DynamikDev\PolicyEngine\Middleware',
        'DynamikDev\PolicyEngine\Models',
        'DynamikDev\PolicyEngine\Resolvers',
        'DynamikDev\PolicyEngine\Stores',
        'DynamikDev\PolicyEngine\Support',
    ];

    // Root namespace files (ServiceProvider, Manager) are also allowed.
    $allowedRoots = [
        'DynamikDev\PolicyEngine\PolicyEngineServiceProvider',
        'DynamikDev\PolicyEngine\PolicyEngineManager',
    ];

    $classes = getNamespacedClasses('src');

    foreach ($classes as $class) {
        $inAllowedNamespace = false;

        foreach ($allowed as $ns) {
            if (str_starts_with($class, $ns.'\\')) {
                $inAllowedNamespace = true;
                break;
            }
        }

        if (! $inAllowedNamespace && ! in_array($class, $allowedRoots, true)) {
            throw new Exception("Class [{$class}] is not in an approved namespace. Approved: ".implode(', ', $allowed));
        }
    }

    expect(true)->toBeTrue();
});

/**
 * Scan src/ for all fully-qualified class names via PSR-4.
 *
 * @return array<int, string>
 */
function getNamespacedClasses(string $srcDir): array
{
    $classes = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $content = file_get_contents($file->getPathname());

        if (preg_match('/namespace\s+(.+?);/', $content, $nsMatch)
            && preg_match('/(?:class|interface|trait|enum)\s+(\w+)/', $content, $classMatch)) {
            $classes[] = $nsMatch[1].'\\'.$classMatch[1];
        }
    }

    return $classes;
}

// --- Namespace contents: each namespace contains the right kind of thing ---

it('requires contracts to be interfaces', function (): void {
    expect('DynamikDev\PolicyEngine\Contracts')
        ->toBeInterfaces();
});

it('requires concerns to be traits', function (): void {
    expect('DynamikDev\PolicyEngine\Concerns')
        ->toBeTraits();
});

it('requires enums to be enums', function (): void {
    expect('DynamikDev\PolicyEngine\Enums')
        ->toBeEnums();
});

it('requires events to be readonly classes', function (): void {
    expect('DynamikDev\PolicyEngine\Events')
        ->toBeReadonly();
});

it('requires DTOs to be readonly classes', function (): void {
    expect('DynamikDev\PolicyEngine\DTOs')
        ->toBeReadonly();
});

it('requires commands to extend Illuminate Command', function (): void {
    expect('DynamikDev\PolicyEngine\Commands')
        ->toExtend('Illuminate\Console\Command');
});

it('requires models to extend Eloquent Model', function (): void {
    expect('DynamikDev\PolicyEngine\Models')
        ->toExtend('Illuminate\Database\Eloquent\Model');
});

it('requires facades to extend Illuminate Facade', function (): void {
    expect('DynamikDev\PolicyEngine\Facades')
        ->toExtend('Illuminate\Support\Facades\Facade');
});

// --- Implementation contracts: implementations must implement their interface ---

it('requires each store to implement its contract', function (string $class, string $contract): void {
    expect($class)->toImplement($contract);
})->with([
    ['DynamikDev\PolicyEngine\Stores\EloquentPermissionStore', 'DynamikDev\PolicyEngine\Contracts\PermissionStore'],
    ['DynamikDev\PolicyEngine\Stores\EloquentRoleStore', 'DynamikDev\PolicyEngine\Contracts\RoleStore'],
    ['DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore', 'DynamikDev\PolicyEngine\Contracts\AssignmentStore'],
    ['DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore', 'DynamikDev\PolicyEngine\Contracts\BoundaryStore'],
    ['DynamikDev\PolicyEngine\Stores\CachingBoundaryStore', 'DynamikDev\PolicyEngine\Contracts\BoundaryStore'],
]);

it('requires evaluators to implement the Evaluator contract', function (): void {
    expect('DynamikDev\PolicyEngine\Evaluators')
        ->toImplement('DynamikDev\PolicyEngine\Contracts\Evaluator');
});

it('requires matchers to implement the Matcher contract', function (): void {
    expect('DynamikDev\PolicyEngine\Matchers')
        ->toImplement('DynamikDev\PolicyEngine\Contracts\Matcher');
});

it('requires policy resolvers to implement the PolicyResolver contract', function (): void {
    expect('DynamikDev\PolicyEngine\Resolvers\IdentityPolicyResolver')->toImplement('DynamikDev\PolicyEngine\Contracts\PolicyResolver');
    expect('DynamikDev\PolicyEngine\Resolvers\BoundaryPolicyResolver')->toImplement('DynamikDev\PolicyEngine\Contracts\PolicyResolver');
    expect('DynamikDev\PolicyEngine\Resolvers\ResourcePolicyResolver')->toImplement('DynamikDev\PolicyEngine\Contracts\PolicyResolver');
    expect('DynamikDev\PolicyEngine\Resolvers\SanctumPolicyResolver')->toImplement('DynamikDev\PolicyEngine\Contracts\PolicyResolver');
    expect('DynamikDev\PolicyEngine\Resolvers\ModelScopeResolver')->toImplement('DynamikDev\PolicyEngine\Contracts\ScopeResolver');
});

it('requires document classes to implement a document contract', function (string $class, string $contract): void {
    expect($class)->toImplement($contract);
})->with([
    ['DynamikDev\PolicyEngine\Documents\JsonDocumentParser', 'DynamikDev\PolicyEngine\Contracts\DocumentParser'],
    ['DynamikDev\PolicyEngine\Documents\DefaultDocumentImporter', 'DynamikDev\PolicyEngine\Contracts\DocumentImporter'],
    ['DynamikDev\PolicyEngine\Documents\DefaultDocumentExporter', 'DynamikDev\PolicyEngine\Contracts\DocumentExporter'],
]);

// --- Dependency boundaries: DX layer uses contracts, not concrete implementations ---

it('prevents traits from depending on concrete stores', function (): void {
    expect('DynamikDev\PolicyEngine\Concerns')
        ->not->toUse('DynamikDev\PolicyEngine\Stores');
});

it('prevents middleware from depending on concrete stores', function (): void {
    expect('DynamikDev\PolicyEngine\Middleware')
        ->not->toUse('DynamikDev\PolicyEngine\Stores');
});

it('prevents commands from depending on concrete stores', function (): void {
    expect('DynamikDev\PolicyEngine\Commands')
        ->not->toUse('DynamikDev\PolicyEngine\Stores');
});

it('prevents facades from depending on concrete stores', function (): void {
    expect('DynamikDev\PolicyEngine\Facades')
        ->not->toUse('DynamikDev\PolicyEngine\Stores');
});

// --- No DB facade ---

it('prevents use of DB facade', function (): void {
    expect('DynamikDev\PolicyEngine')
        ->not->toUse('Illuminate\Support\Facades\DB');
});

// --- Comment style: consecutive // comments must use block syntax ---

it('requires multi-line comments to use block syntax', function (): void {
    $iterators = new AppendIterator;
    $iterators->append(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator('src', FilesystemIterator::SKIP_DOTS),
    ));
    $iterators->append(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator('tests', FilesystemIterator::SKIP_DOTS),
    ));

    $violations = [];

    foreach ($iterators as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $lines = file($file->getPathname());
        $prevWasComment = false;

        foreach ($lines as $number => $line) {
            $trimmed = ltrim($line);
            $isComment = str_starts_with($trimmed, '// ') || $trimmed === '//';

            if ($isComment && $prevWasComment) {
                $violations[] = $file->getPathname().':'.($number + 1);
                break;
            }

            $prevWasComment = $isComment;
        }
    }

    expect($violations)->toBeEmpty(
        'Consecutive // comments found — use /* */ block syntax instead: '.implode(', ', $violations),
    );
});
