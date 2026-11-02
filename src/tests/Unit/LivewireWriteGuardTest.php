<?php

namespace Tests\Unit;

use App\Livewire\Concerns\ReportsErrors;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Read-only sessions (the demo, an admin viewing as someone) are refused in one place,
 * ReportsErrors::attempt(). This keeps that true: a public action on a ledger component either
 * goes through attempt() or is listed here as one that only reads or edits form state.
 */
class LivewireWriteGuardTest extends TestCase
{
    /** Public methods that never write to the database. */
    private const READS_ONLY = ['mount', 'render', 'updatingSearch', 'suggestions', 'unitCopper', 'pick', 'edit', 'cancel', 'selectAll', 'clearSelection', 'sortBy', 'updatedMode'];

    public function test_every_write_action_goes_through_attempt(): void
    {
        $checked = 0;

        foreach (glob(dirname(__DIR__, 2).'/app/Livewire/*.php') as $file) {
            $class = new ReflectionClass('App\\Livewire\\'.basename($file, '.php'));

            if (! in_array(ReportsErrors::class, $class->getTraitNames(), true)) {
                continue;
            }

            $lines = file($file);

            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                // Methods that come from traits (pagination, this trait) are defined in other files.
                if (realpath($method->getFileName()) !== realpath($file) || in_array($method->getName(), self::READS_ONLY, true)) {
                    continue;
                }

                $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

                $this->assertStringContainsString('$this->attempt(', $body, "{$class->getShortName()}::{$method->getName()}() writes without attempt(), so read-only sessions are not refused");
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(10, $checked, 'the scan found too few actions; is it looking in the right place?');
    }
}
