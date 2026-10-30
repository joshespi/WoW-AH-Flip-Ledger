<?php

namespace Tests\Support;

trait Fixtures
{
    protected function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../fixtures/'.$name);
    }

    /**
     * @return array<string, mixed>
     */
    protected function golden(string $name): array
    {
        return json_decode($this->fixture('golden/'.$name.'.golden.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
