<?php

declare(strict_types=1);

// --- Strict types ---

it('enforces strict types across the package', function (): void {
    expect('DynamikDev\Marque')
        ->toUseStrictTypes();
});

it('enforces strict types in tests', function (): void {
    expect('Tests')
        ->toUseStrictTypes();
});

// --- Namespace structure: every class must live in an approved namespace ---

it('only allows known namespaces', function (): void {
    $allowed = [
        'DynamikDev\Marque\Attributes',
        'DynamikDev\Marque\Commands',
        'DynamikDev\Marque\Concerns',
        'DynamikDev\Marque\Conditions',
        'DynamikDev\Marque\Contracts',
        'DynamikDev\Marque\Documents',
        'DynamikDev\Marque\DTOs',
        'DynamikDev\Marque\Enums',
        'DynamikDev\Marque\Evaluators',
        'DynamikDev\Marque\Events',
        'DynamikDev\Marque\Facades',
        'DynamikDev\Marque\Listeners',
        'DynamikDev\Marque\Matchers',
        'DynamikDev\Marque\Middleware',
        'DynamikDev\Marque\Models',
        'DynamikDev\Marque\Resolvers',
        'DynamikDev\Marque\Stores',
        'DynamikDev\Marque\Support',
    ];

    // Root namespace files (ServiceProvider, Manager) are also allowed.
    $allowedRoots = [
        'DynamikDev\Marque\MarqueServiceProvider',
        'DynamikDev\Marque\MarqueManager',
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
    expect('DynamikDev\Marque\Contracts')
        ->toBeInterfaces();
});

it('requires concerns to be traits', function (): void {
    expect('DynamikDev\Marque\Concerns')
        ->toBeTraits();
});

it('requires enums to be enums', function (): void {
    expect('DynamikDev\Marque\Enums')
        ->toBeEnums();
});

it('requires events to be readonly classes', function (): void {
    expect('DynamikDev\Marque\Events')
        ->toBeReadonly();
});

it('requires DTOs to be readonly classes', function (): void {
    expect('DynamikDev\Marque\DTOs')
        ->toBeReadonly();
});

it('requires commands to extend Illuminate Command', function (): void {
    expect('DynamikDev\Marque\Commands')
        ->toExtend('Illuminate\Console\Command');
});

it('requires models to extend Eloquent Model', function (): void {
    expect('DynamikDev\Marque\Models')
        ->toExtend('Illuminate\Database\Eloquent\Model');
});

it('requires facades to extend Illuminate Facade', function (): void {
    expect('DynamikDev\Marque\Facades')
        ->toExtend('Illuminate\Support\Facades\Facade');
});

// --- Implementation contracts: implementations must implement their interface ---

it('requires each store to implement its contract', function (string $class, string $contract): void {
    expect($class)->toImplement($contract);
})->with([
    ['DynamikDev\Marque\Stores\EloquentPermissionStore', 'DynamikDev\Marque\Contracts\PermissionStore'],
    ['DynamikDev\Marque\Stores\EloquentRoleStore', 'DynamikDev\Marque\Contracts\RoleStore'],
    ['DynamikDev\Marque\Stores\EloquentAssignmentStore', 'DynamikDev\Marque\Contracts\AssignmentStore'],
    ['DynamikDev\Marque\Stores\EloquentBoundaryStore', 'DynamikDev\Marque\Contracts\BoundaryStore'],
    ['DynamikDev\Marque\Stores\CachingBoundaryStore', 'DynamikDev\Marque\Contracts\BoundaryStore'],
]);

it('requires evaluators to implement the Evaluator contract', function (): void {
    expect('DynamikDev\Marque\Evaluators')
        ->toImplement('DynamikDev\Marque\Contracts\Evaluator');
});

it('requires matchers to implement the Matcher contract', function (): void {
    expect('DynamikDev\Marque\Matchers')
        ->toImplement('DynamikDev\Marque\Contracts\Matcher');
});

it('requires policy resolvers to implement the PolicyResolver contract', function (): void {
    expect('DynamikDev\Marque\Resolvers\IdentityPolicyResolver')->toImplement('DynamikDev\Marque\Contracts\PolicyResolver');
    expect('DynamikDev\Marque\Resolvers\BoundaryPolicyResolver')->toImplement('DynamikDev\Marque\Contracts\PolicyResolver');
    expect('DynamikDev\Marque\Resolvers\ResourcePolicyResolver')->toImplement('DynamikDev\Marque\Contracts\PolicyResolver');
    expect('DynamikDev\Marque\Resolvers\SanctumPolicyResolver')->toImplement('DynamikDev\Marque\Contracts\PolicyResolver');
    expect('DynamikDev\Marque\Resolvers\ModelScopeResolver')->toImplement('DynamikDev\Marque\Contracts\ScopeResolver');
});

it('requires document classes to implement a document contract', function (string $class, string $contract): void {
    expect($class)->toImplement($contract);
})->with([
    ['DynamikDev\Marque\Documents\JsonDocumentParser', 'DynamikDev\Marque\Contracts\DocumentParser'],
    ['DynamikDev\Marque\Documents\DefaultDocumentImporter', 'DynamikDev\Marque\Contracts\DocumentImporter'],
    ['DynamikDev\Marque\Documents\DefaultDocumentExporter', 'DynamikDev\Marque\Contracts\DocumentExporter'],
]);

// --- Dependency boundaries: DX layer uses contracts, not concrete implementations ---

it('prevents traits from depending on concrete stores', function (): void {
    expect('DynamikDev\Marque\Concerns')
        ->not->toUse('DynamikDev\Marque\Stores');
});

it('prevents middleware from depending on concrete stores', function (): void {
    expect('DynamikDev\Marque\Middleware')
        ->not->toUse('DynamikDev\Marque\Stores');
});

it('prevents commands from depending on concrete stores', function (): void {
    expect('DynamikDev\Marque\Commands')
        ->not->toUse('DynamikDev\Marque\Stores');
});

it('prevents facades from depending on concrete stores', function (): void {
    expect('DynamikDev\Marque\Facades')
        ->not->toUse('DynamikDev\Marque\Stores');
});

// --- No DB facade ---

it('prevents use of DB facade', function (): void {
    expect('DynamikDev\Marque')
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
