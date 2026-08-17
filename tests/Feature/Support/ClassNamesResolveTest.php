<?php

namespace Tests\Feature\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Every class name in app/ resolves at runtime.
 *
 * Written after a dropped `use` line shipped a controller that saved a
 * complaint and then threw on the very next line: the record was created, the
 * customer got "something went wrong", and nothing in the suite noticed because
 * no test covered that one path.
 *
 * PHP only complains about a missing import when the line actually runs, so a
 * name inside a catch block or a rarely-taken branch can sit broken for weeks.
 * This walks the source instead and asks whether each name would resolve.
 */
class ClassNamesResolveTest extends TestCase
{
    public function test_every_class_name_used_in_app_resolves(): void
    {
        $problems = [];

        foreach ($this->sourceFiles() as $path) {
            $source = file_get_contents($path);

            preg_match('/^namespace ([^;]+);/m', $source, $ns);
            $namespace = $ns[1] ?? '';

            $imported = $this->importedNames($source);

            // Static calls and instantiations: `Foo::bar()`, `new Foo(`.
            preg_match_all('/(?<![\\\\\w>$])([A-Z][A-Za-z0-9_]*)(?:::|\s*\()/', $source, $matches);

            foreach (array_unique($matches[1]) as $name) {
                if ($this->resolves($name, $namespace, $imported)) {
                    continue;
                }

                $problems[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).' uses '.$name;
            }
        }

        $this->assertSame([], array_values(array_unique($problems)),
            "These names will not resolve when the line runs:\n".implode("\n", array_unique($problems)));
    }

    /**
     * @return array<int, string>
     */
    private function sourceFiles(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function importedNames(string $source): array
    {
        preg_match_all('/^use (?:function )?([A-Za-z0-9_\\\\]+)(?: as (\w+))?;/m', $source, $uses);

        $names = [];

        foreach ($uses[1] as $i => $fullyQualified) {
            $alias = $uses[2][$i] ?? '';
            $names[] = $alias !== ''
                ? $alias
                : substr((string) strrchr('\\'.$fullyQualified, '\\'), 1);
        }

        return $names;
    }

    /**
     * @param  array<int, string>  $imported
     */
    private function resolves(string $name, string $namespace, array $imported): bool
    {
        if (in_array($name, $imported, true)) {
            return true;
        }

        // Language constructs and a few constants the pattern picks up.
        if (in_array($name, ['self', 'static', 'parent', 'Closure', 'PHP_INT_MAX', 'STR_PAD_LEFT'], true)) {
            return true;
        }

        /*
         * SQL functions inside raw query strings - SUM(, COUNT(, FIELD(,
         * DATE_FORMAT( - look exactly like a class being instantiated. They are
         * always upper case and a class name never is, so that is the tell.
         */
        if ($name === strtoupper($name)) {
            return true;
        }

        foreach ([$namespace.'\\'.$name, $name] as $candidate) {
            if (class_exists($candidate) || interface_exists($candidate) || enum_exists($candidate)) {
                return true;
            }
        }

        return false;
    }
}
