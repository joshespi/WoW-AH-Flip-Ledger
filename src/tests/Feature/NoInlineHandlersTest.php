<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class NoInlineHandlersTest extends TestCase
{
    /** The CSP blocks inline event handler attributes, so they silently do nothing. */
    public function test_views_have_no_inline_event_handlers(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (preg_match('/\son[a-z]{3,}\s*="/i', $file->getContents())) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Use Alpine @click/@submit instead of on*= attributes.');
    }
}
