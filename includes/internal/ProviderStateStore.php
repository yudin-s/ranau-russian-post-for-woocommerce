<?php

declare(strict_types=1);

namespace Ranau\RussianPost\Internal\DeliveryState;

use DomainException;

final class ProviderStateStore
{
    private string $rateId;

    /** @var callable */
    private $read;

    /** @var callable */
    private $write;

    /** @var callable */
    private $delete;

    /** @var callable */
    private $now;

    /** @var callable */
    private $tokenFactory;

    public function __construct(
        string $rateId,
        callable $read,
        callable $write,
        callable $delete,
        ?callable $now = null,
        ?callable $tokenFactory = null
    ) {
        $rateId = trim($rateId);

        if ($rateId === '') {
            throw new DomainException('missing_rate_id');
        }

        $this->rateId = $rateId;
        $this->read = $read;
        $this->write = $write;
        $this->delete = $delete;
        $this->now = $now ?? static fn (): int => time();
        $this->tokenFactory = $tokenFactory ?? static fn (): string => bin2hex(random_bytes(32));
    }

    /**
     * @param array<string, mixed> $selection
     * @param array<string, mixed> $quote
     * @return array<string, mixed>
     */
    public function beginPending(
        string $contextKey,
        string $packageFingerprint,
        array $selection,
        array $quote,
        int $ttl = 300
    ): array {
        $contextKey = trim($contextKey);
        $packageFingerprint = trim($packageFingerprint);

        if ($contextKey === '' || $packageFingerprint === '') {
            throw new DomainException('missing_context');
        }

        if ($ttl < 1 || $ttl > 900) {
            throw new DomainException('invalid_ttl');
        }

        $token = (string) ($this->tokenFactory)();

        if (strlen($token) < 32) {
            throw new DomainException('invalid_commit_token');
        }

        $now = (int) ($this->now)();
        $pending = [
            'rate_id' => $this->rateId,
            'context_key' => $contextKey,
            'package_fingerprint' => $packageFingerprint,
            'selection' => $selection,
            'quote' => $quote,
            'token_hash' => hash('sha256', $token),
            'created_at' => $now,
            'expires_at' => $now + $ttl,
        ];

        ($this->write)(['pending' => $pending]);

        return [
            'rate_id' => $this->rateId,
            'context_key' => $contextKey,
            'package_fingerprint' => $packageFingerprint,
            'commit_token' => $token,
            'expires_at' => $pending['expires_at'],
        ];
    }

    /**
     * Commit only server-owned selection and quote data. Client input is used
     * solely to prove ownership of the current rate/context/pending token.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function commit(
        array $data,
        string $selectedRateId,
        string $currentContextKey,
        string $currentPackageFingerprint
    ): array {
        $providedRateId = isset($data['rate_id']) ? trim((string) $data['rate_id']) : '';
        $providedContextKey = isset($data['context_key']) ? trim((string) $data['context_key']) : '';
        $providedFingerprint = isset($data['package_fingerprint'])
            ? trim((string) $data['package_fingerprint'])
            : '';
        $providedToken = isset($data['commit_token']) ? (string) $data['commit_token'] : '';

        if ($providedRateId !== $this->rateId || trim($selectedRateId) !== $this->rateId) {
            throw new DomainException('inactive_rate');
        }

        if (
            $providedContextKey === '' ||
            $providedContextKey !== trim($currentContextKey) ||
            $providedFingerprint === '' ||
            $providedFingerprint !== trim($currentPackageFingerprint)
        ) {
            throw new DomainException('stale_context');
        }

        if (strlen($providedToken) < 32) {
            throw new DomainException('invalid_commit_token');
        }

        $state = $this->readState();
        $tokenHash = hash('sha256', $providedToken);
        $committed = isset($state['committed']) && is_array($state['committed'])
            ? $state['committed']
            : [];

        if (
            isset($committed['token_hash']) &&
            hash_equals((string) $committed['token_hash'], $tokenHash) &&
            $this->recordMatchesContext($committed, $providedContextKey, $providedFingerprint)
        ) {
            if ((int) ($committed['expires_at'] ?? 0) < (int) ($this->now)()) {
                $this->invalidate();
                throw new DomainException('expired_committed_state');
            }

            return $this->publicCommittedState($committed, true);
        }

        $pending = isset($state['pending']) && is_array($state['pending'])
            ? $state['pending']
            : [];

        if ($pending === []) {
            throw new DomainException('missing_pending_state');
        }

        if ((int) ($pending['expires_at'] ?? 0) < (int) ($this->now)()) {
            $this->invalidate();
            throw new DomainException('expired_pending_state');
        }

        if (!$this->recordMatchesContext($pending, $providedContextKey, $providedFingerprint)) {
            throw new DomainException('stale_pending_state');
        }

        if (
            !isset($pending['token_hash']) ||
            !hash_equals((string) $pending['token_hash'], $tokenHash)
        ) {
            throw new DomainException('invalid_commit_token');
        }

        $pending['committed_at'] = (int) ($this->now)();
        ($this->write)(['committed' => $pending]);

        return $this->publicCommittedState($pending, false);
    }

    public function invalidate(): void
    {
        ($this->delete)();
    }

    /** @return array<string, mixed>|null */
    public function committed(): ?array
    {
        $state = $this->readState();

        if (!isset($state['committed']) || !is_array($state['committed'])) {
            return null;
        }

        return $this->publicCommittedState($state['committed'], false);
    }

    /** @return array<string, mixed> */
    private function readState(): array
    {
        $state = ($this->read)();

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $record */
    private function recordMatchesContext(
        array $record,
        string $contextKey,
        string $packageFingerprint
    ): bool {
        return ($record['rate_id'] ?? '') === $this->rateId &&
            ($record['context_key'] ?? '') === $contextKey &&
            ($record['package_fingerprint'] ?? '') === $packageFingerprint;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function publicCommittedState(array $record, bool $replayed): array
    {
        return [
            'rate_id' => $this->rateId,
            'context_key' => (string) ($record['context_key'] ?? ''),
            'package_fingerprint' => (string) ($record['package_fingerprint'] ?? ''),
            'selection' => isset($record['selection']) && is_array($record['selection'])
                ? $record['selection']
                : [],
            'quote' => isset($record['quote']) && is_array($record['quote'])
                ? $record['quote']
                : [],
            'committed_at' => (int) ($record['committed_at'] ?? 0),
            'replayed' => $replayed,
        ];
    }
}
