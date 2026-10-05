<?php

declare(strict_types=1);

namespace App\Domain\AI;

use DomainException;

final readonly class AiDraftWriteContract
{
    private const STATUSES = ['conflict', 'updated'];

    public function __construct(private AiToolPolicy $policy)
    {
    }

    public function contentDescriptor(): AiToolDescriptor
    {
        return $this->descriptor('content.draft.update');
    }

    public function settingsDescriptor(): AiToolDescriptor
    {
        return $this->descriptor('settings.draft.update');
    }

    /** @param array<string, mixed> $inputs */
    public function validateContentInputs(array $inputs): void
    {
        $this->validateInputs($this->contentDescriptor(), $inputs, 'content_draft');
    }

    /** @param array<string, mixed> $outputs */
    public function validateContentOutputs(array $outputs): void
    {
        $this->validateOutputs($this->contentDescriptor(), $outputs, 'content_draft');
    }

    /** @param array<string, mixed> $inputs */
    public function validateSettingsInputs(array $inputs): void
    {
        $this->validateInputs($this->settingsDescriptor(), $inputs, 'settings_draft');
    }

    /** @param array<string, mixed> $outputs */
    public function validateSettingsOutputs(array $outputs): void
    {
        $this->validateOutputs($this->settingsDescriptor(), $outputs, 'settings_draft');
    }

    private function descriptor(string $tool): AiToolDescriptor
    {
        return AiToolDescriptor::fromArray($this->policy, [
            'tool' => $tool,
            'risk' => AiToolPolicy::REVERSIBLE_WRITE,
            'input_names' => ['change_ref', 'draft_ref', 'expected_revision_ref'],
            'required_inputs' => ['change_ref', 'draft_ref'],
            'output_names' => ['draft_ref', 'revision_ref', 'status'],
            'required_outputs' => ['draft_ref', 'revision_ref', 'status'],
        ]);
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private function validateInputs(
        AiToolDescriptor $descriptor,
        array $inputs,
        string $draftPrefix,
    ): void {
        $descriptor->validateInputs($inputs);
        self::ref($inputs['draft_ref'], $draftPrefix, 'draft_ref');
        self::ref($inputs['change_ref'], 'change', 'change_ref');

        if (($inputs['expected_revision_ref'] ?? null) !== null) {
            self::ref(
                $inputs['expected_revision_ref'],
                'revision',
                'expected_revision_ref',
            );
        }
    }

    /**
     * @param array<string, mixed> $outputs
     */
    private function validateOutputs(
        AiToolDescriptor $descriptor,
        array $outputs,
        string $draftPrefix,
    ): void {
        $descriptor->validateOutputs($outputs);
        self::ref($outputs['draft_ref'], $draftPrefix, 'draft_ref');
        self::ref($outputs['revision_ref'], 'revision', 'revision_ref');
        self::status($outputs['status']);
    }

    private static function ref(mixed $value, string $prefix, string $field): void
    {
        $pattern = '/^' . preg_quote($prefix, '/') . ':[a-z0-9][a-z0-9_-]{0,63}$/D';
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new DomainException($field . ' no es referencia canónica.');
        }
    }

    private static function status(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::STATUSES, true)) {
            throw new DomainException('status de draft fuera de la allowlist.');
        }

        return $value;
    }
}
