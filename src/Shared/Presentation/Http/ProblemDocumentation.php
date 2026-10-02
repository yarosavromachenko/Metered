<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http;

use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Fixes the generated OpenAPI errors: validation errors use the
 * {@see ProblemRenderer} shape, not Laravel's, and 401/403/429 from the
 * middleware are added to every route. Dev and CI only.
 */
final readonly class ProblemDocumentation
{
    private const string VALIDATION_RESPONSE = 'ValidationException';

    public function __invoke(OpenApi $openApi): void
    {
        $problem = $openApi->components->addSchema('Problem', $this->schema($this->problem()));
        $validation = $openApi->components->addSchema('ValidationProblem', $this->schema($this->validationProblem()));

        $openApi->components->responses[self::VALIDATION_RESPONSE] = $this->problemResponse(
            422,
            'The request did not pass validation. `validation-failed` lists every failing field in `errors`; '
            . '`invalid-event` names the one event whose value is impossible in `pointer`.',
            $validation,
        );

        foreach ($openApi->paths as $path) {
            if (! $path instanceof Path) {
                continue;
            }

            foreach ($path->operations as $operation) {
                if ($operation instanceof Operation) {
                    $this->addEdgeResponses($operation, $problem);
                }
            }
        }
    }

    private function addEdgeResponses(Operation $operation, Reference $problem): void
    {
        $operation->addResponse($this->problemResponse(
            401,
            'No API key, an unknown or wrong one (`invalid-api-key`), or a revoked one (`revoked-api-key`).',
            $problem,
        ));

        $operation->addResponse($this->problemResponse(
            403,
            'The key does not carry the scope this route needs (`insufficient-scope`).',
            $problem,
        ));

        $operation->addResponse($this->problemResponse(
            429,
            'The key has spent its per-minute budget. `Retry-After` says when to try again.',
            $problem,
        ));
    }

    private function problemResponse(int $status, string $description, Reference $schema): Response
    {
        return new Response($status)
            ->setDescription($description)
            ->setContent('application/problem+json', $schema);
    }

    /**
     * Built by hand because the generator's factory is untyped.
     */
    private function schema(ObjectType $type): Schema
    {
        $schema = new Schema();
        $schema->type = $type;

        return $schema;
    }

    private function problem(): ObjectType
    {
        $problem = new ObjectType();
        $problem->addProperty('type', new StringType()->format('uri')
            ->setDescription('Identifies the kind of problem; stable, and what a client should branch on.'));
        $problem->addProperty('title', new StringType());
        $problem->addProperty('status', new IntegerType());
        $problem->addProperty('detail', new StringType()->setDescription('Human-readable, and not stable.'));
        $problem->addProperty('instance', new StringType()->setDescription('The path of the request that failed.'));
        $problem->setRequired(['type', 'title', 'status', 'detail', 'instance']);

        return $problem;
    }

    private function validationProblem(): ObjectType
    {
        $error = new ObjectType();
        $error->addProperty('pointer', new StringType()->setDescription('A JSON pointer to the field, e.g. `/events/3/quantity`.'));
        $error->addProperty('detail', new StringType());
        $error->setRequired(['pointer', 'detail']);

        $problem = $this->problem();
        $errors = new ArrayType();
        $errors->setItems($error);
        $errors->setDescription('Every failing field, for `validation-failed`.');

        $problem->addProperty('errors', $errors);
        $problem->addProperty('pointer', new StringType()
            ->setDescription('The event whose value is impossible, for `invalid-event`, e.g. `/events/3`.'));

        return $problem;
    }
}
