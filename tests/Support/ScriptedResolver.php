<?php

declare(strict_types=1);

namespace Tests\Support;

use Metered\Webhooks\Infrastructure\Http\Resolver;

/**
 * A DNS server that answers from a script, one answer per question — which
 * is how a rebinding attack looks from the asking side.
 */
final class ScriptedResolver implements Resolver
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param list<list<string>> $answers
     */
    public function __construct(private array $answers) {}

    public function resolve(string $host): array
    {
        $this->asked[] = $host;

        return array_shift($this->answers) ?? [];
    }
}
