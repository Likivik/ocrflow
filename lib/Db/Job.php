<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Likivik
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OcrFlow\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One row of `ocrflow_jobs`.
 *
 * Written as a plain Entity with explicit setters rather than with magic
 * properties: the columns are read in three places (worker claim, status API,
 * background reconcile) and a typo in a magic property is silent.
 *
 * @method int getId()
 * @method ?int getFileId()
 * @method string getPath()
 * @method string getStatus()
 * @method ?string getReason()
 * @method ?string getReasonCode()
 * @method ?bool getRetryable()
 * @method ?string getEngine()
 * @method ?string getClaimToken()
 * @method ?int getClaimedByWorkerId()
 * @method int getAttempts()
 * @method ?int getSize()
 * @method ?string getEtag()
 * @method ?int getPages()
 * @method ?int getChars()
 * @method ?string getError()
 * @method int getCreatedAt()
 * @method ?int getClaimedAt()
 * @method ?int getFinishedAt()
 */
class Job extends Entity
{
	protected ?int $fileId = null;
	protected string $path = '';
	protected string $status = 'queued';
	protected ?string $reason = null;
	protected ?string $reasonCode = null;
	protected ?bool $retryable = null;
	protected ?string $engine = null;
	protected ?string $claimToken = null;
	protected ?int $claimedByWorkerId = null;
	protected int $attempts = 0;
	protected ?int $size = null;
	protected ?string $etag = null;
	protected ?int $pages = null;
	protected ?int $chars = null;
	protected ?string $error = null;
	protected int $createdAt = 0;
	protected ?int $claimedAt = null;
	protected ?int $finishedAt = null;

	public function __construct()
	{
		$this->addType('fileId', 'integer');
		$this->addType('attempts', 'integer');
		$this->addType('size', 'integer');
		$this->addType('pages', 'integer');
		$this->addType('chars', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('claimedAt', 'integer');
		$this->addType('finishedAt', 'integer');
		$this->addType('claimedByWorkerId', 'integer');
	}

	/**
	 * A nullable bool column: DBAL returns true/false on pgsql but a driver
	 * or a test harness may hand back '0'/'1'/'t', and (bool) '0' is true —
	 * exactly the wrong answer for a retry flag.
	 */
	private static function toOptionalBool(mixed $value): ?bool
	{
		if ($value === null) {
			return null;
		}
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value)) {
			return $value !== 0;
		}
		if (is_string($value)) {
			return strtolower($value) === 't' || $value === '1' || strtolower($value) === 'true';
		}
		return (bool) $value;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): static
	{
		$job = new static();
		$job->setId((int) $row['id']);
		$job->setFileId($row['file_id'] === null ? null : (int) $row['file_id']);
		$job->setPath((string) $row['path']);
		$job->setStatus((string) $row['status']);
		$job->setReason($row['reason'] === null ? null : (string) $row['reason']);
		// reason_code and retryable joined the table in 0.1.2 and must
		// survive a read: the attention worklist reads the code through
		// `getById()`, and a dropped code made every needs_fix row look
		// uncoded — unlistable and unretryable.
		$job->setReasonCode(($row['reason_code'] ?? null) === null ? null : (string) $row['reason_code']);
		$job->setRetryable(self::toOptionalBool($row['retryable'] ?? null));
		$job->setEngine($row['engine'] === null ? null : (string) $row['engine']);
		$job->setClaimToken(isset($row['claim_token']) && $row['claim_token'] !== null ? (string) $row['claim_token'] : null);
		$job->setClaimedByWorkerId(isset($row['claimed_by_worker_id']) && $row['claimed_by_worker_id'] !== null ? (int) $row['claimed_by_worker_id'] : null);
		$job->setAttempts((int) ($row['attempts'] ?? 0));
		$job->setSize($row['size'] === null ? null : (int) $row['size']);
		$job->setEtag($row['etag'] === null ? null : (string) $row['etag']);
		$job->setPages($row['pages'] === null ? null : (int) $row['pages']);
		$job->setChars($row['chars'] === null ? null : (int) $row['chars']);
		$job->setError($row['error'] === null ? null : (string) $row['error']);
		$job->setCreatedAt((int) ($row['created_at'] ?? 0));
		$job->setClaimedAt($row['claimed_at'] === null ? null : (int) $row['claimed_at']);
		$job->setFinishedAt($row['finished_at'] === null ? null : (int) $row['finished_at']);
		$job->resetUpdatedFields();
		return $job;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toApi(): array
	{
		// snake_case, matching the column names and the worker's dataclass. This
		// used to emit camelCase (`fileId`), and the worker's `.get('file_id')`
		// then returned None instead of failing — a silent loss of the field the
		// queue's uniqueness depends on.
		return [
			'id' => $this->getId(),
			'file_id' => $this->getFileId(),
			'path' => $this->getPath(),
			'status' => $this->getStatus(),
			'reason' => $this->getReason(),
			'engine' => $this->getEngine(),
			'claim_token' => $this->getClaimToken(),
			'attempts' => $this->getAttempts(),
			'size' => $this->getSize(),
			'etag' => $this->getEtag(),
			'pages' => $this->getPages(),
			'chars' => $this->getChars(),
			'error' => $this->getError(),
			'created_at' => $this->getCreatedAt(),
			'claimed_at' => $this->getClaimedAt(),
			'finished_at' => $this->getFinishedAt(),
		];
	}
}
