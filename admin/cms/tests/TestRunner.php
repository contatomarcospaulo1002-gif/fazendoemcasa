<?php
declare(strict_types=1);

/**
 * Mini-runner de testes. Sem dependências externas.
 *
 * Uso:
 *   php admin/cms/tests/run.php
 *   php admin/cms/tests/run.php --group=security
 *   php admin/cms/tests/run.php --filter=PathGuard
 */

final class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    /** @var list<string> */
    private array $failures = [];
    private string $currentGroup = '';

    public function group(string $name): void
    {
        $this->currentGroup = $name;
        echo "\n== $name ==\n";
    }

    public function ok(string $label, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo "  OK   $label\n";
            return;
        }
        $this->failed++;
        $msg = $this->currentGroup . ' :: ' . $label . ($detail !== '' ? " — $detail" : '');
        $this->failures[] = $msg;
        echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }

    public function same(string $label, mixed $expected, mixed $actual): void
    {
        $this->ok(
            $label,
            $expected === $actual,
            'esperado ' . var_export($expected, true) . ', obtido ' . var_export($actual, true)
        );
    }

    public function throws(string $label, callable $fn, string $exceptionClass = \Throwable::class): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->ok($label, $e instanceof $exceptionClass, 'lançou ' . $e::class . ': ' . $e->getMessage());
            return;
        }
        $this->ok($label, false, 'não lançou exceção');
    }

    public function summary(): int
    {
        $total = $this->passed + $this->failed;
        echo "\n";
        if ($this->failed === 0) {
            echo "OK $this->passed/$total\n";
            return 0;
        }
        echo "FALHAS: $this->failed de $total\n";
        foreach ($this->failures as $f) {
            echo "  - $f\n";
        }
        return 1;
    }
}
