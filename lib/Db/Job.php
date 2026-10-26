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
 * @method ?string getEngine()
 * @method ?string getClaimedBy()
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
	protected ?string $engine = null;
	protected ?string $claimedBy = null;
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
		$job->setEngine($row['engine'] === null ? null : (string) $row['engine']);
		$job->setClaimedBy($row['claimed_by'] === null ? null : (string) $row['claimed_by']);
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
		return [
			'id' => $this->getId(),
			'fileId' => $this->getFileId(),
			'path' => $this->getPath(),
			'status' => $this->getStatus(),
			'reason' => $this->getReason(),
			'engine' => $this->getEngine(),
			'attempts' => $this->getAttempts(),
			'size' => $this->getSize(),
			'etag' => $this->getEtag(),
			'pages' => $this->getPages(),
			'chars' => $this->getChars(),
			'error' => $this->getError(),
			'createdAt' => $this->getCreatedAt(),
			'claimedAt' => $this->getClaimedAt(),
			'finishedAt' => $this->getFinishedAt(),
		];
	}
}
