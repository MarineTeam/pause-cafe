<?php

namespace PauseCafe;

use PDOException;

/**
 * The prepaid wallet, as an append-only ledger.
 *
 * There is no balance column anywhere. A balance is the sum of the entries, and
 * every entry says who moved the money, when, why, and what it refers to. That
 * is the only way an unexpected balance can ever be explained, and this holds
 * real money members have handed over.
 */
class Wallet {

	public const KIND_TOPUP      = 'topup';
	public const KIND_ORDER      = 'order';
	public const KIND_REFUND     = 'refund';
	public const KIND_ADJUSTMENT = 'adjustment';
	public const KIND_ZEFFY      = 'zeffy';

	/**
	 * Rewrites the running total stored against each entry.
	 *
	 * The balance itself is always the sum of the deltas, so it is never wrong.
	 * What can go wrong is the number printed beside each line of a statement:
	 * it was worked out when the entry was written, and removing an earlier
	 * entry leaves everything below the gap carrying on from a figure that no
	 * longer follows. Only deleting history needs this, which is the one thing
	 * an append-only ledger was not built to do.
	 */
	public static function recompute( int $userId ): void {
		$pdo = Database::pdo();

		$rows = $pdo->prepare( 'SELECT id, delta_cents FROM wallet_entries WHERE user_id = ? ORDER BY id' );
		$rows->execute( array( $userId ) );

		$update  = $pdo->prepare( 'UPDATE wallet_entries SET balance_after_cents = ? WHERE id = ?' );
		$running = 0;

		foreach ( $rows->fetchAll() as $row ) {
			$running += (int) $row['delta_cents'];

			$update->execute( array( $running, (int) $row['id'] ) );
		}
	}

	public static function balance( int $userId ): int {
		$statement = Database::pdo()->prepare(
			'SELECT COALESCE(SUM(delta_cents), 0) FROM wallet_entries WHERE user_id = ?'
		);

		$statement->execute( array( $userId ) );

		return (int) $statement->fetchColumn();
	}

	/**
	 * @return array[] Newest first.
	 */
	public static function entries( int $userId, int $limit = 100 ): array {
		$statement = Database::pdo()->prepare(
			'SELECT e.*, u.name AS created_by_name
			 FROM wallet_entries e
			 LEFT JOIN users u ON u.id = e.created_by
			 WHERE e.user_id = ?
			 ORDER BY e.id DESC
			 LIMIT ?'
		);

		$statement->execute( array( $userId, $limit ) );

		return $statement->fetchAll();
	}

	/**
	 * Writes one ledger entry and returns the new balance.
	 *
	 * Callers already inside a transaction pass $ownTransaction = false so the
	 * order and its debit commit or fail together.
	 *
	 * When it does open its own, it opens an immediate one, and that word is
	 * the whole of the difference. This reads the balance and then writes a row
	 * derived from it, which is the shape that goes wrong under concurrency --
	 * and PDO's beginTransaction() issues a plain BEGIN, which in SQLite takes
	 * no write lock until the first write. Two of those overlap happily until
	 * the second tries to upgrade, at which point WAL refuses it outright with
	 * SQLITE_BUSY_SNAPSHOT rather than let the read go stale. Safe, but it
	 * surfaces as "database is locked" on a webhook that had done nothing
	 * wrong, and busy_timeout cannot help because a stale snapshot is a
	 * conflict rather than a wait.
	 *
	 * Taking the write lock up front turns that into the wait it should have
	 * been. The reason it belongs here rather than in the callers is that an
	 * invariant every caller has to remember is one a caller will eventually
	 * forget -- and the balance is not the thing to find that out about.
	 *
	 * @throws \RuntimeException When a reference has already been posted.
	 */
	public static function post(
		int $userId,
		int $deltaCents,
		string $kind,
		string $note = '',
		string $reference = '',
		?int $createdBy = null,
		bool $ownTransaction = true
	): int {
		$pdo = Database::pdo();

		/*
		 * exec() rather than PDO::beginTransaction(), which can only issue a
		 * plain BEGIN. PDO therefore does not know a transaction is open, so
		 * commit() and rollBack() are out too and inTransaction() would lie --
		 * hence tracking it here instead of asking.
		 */
		if ( $ownTransaction ) {
			$pdo->exec( 'BEGIN IMMEDIATE' );
		}

		try {
			$balance = self::balance( $userId ) + $deltaCents;

			$statement = $pdo->prepare(
				'INSERT INTO wallet_entries
					(user_id, delta_cents, balance_after_cents, kind, note, reference, created_by, created_at)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
			);

			$statement->execute(
				array(
					$userId,
					$deltaCents,
					$balance,
					$kind,
					$note,
					$reference,
					$createdBy,
					gmdate( 'Y-m-d H:i:s' ),
				)
			);

			if ( $ownTransaction ) {
				$pdo->exec( 'COMMIT' );
			}

			return $balance;
		} catch ( PDOException $e ) {
			if ( $ownTransaction ) {
				try {
					$pdo->exec( 'ROLLBACK' );
				} catch ( \Throwable $ignored ) {
					// Never opened, or already unwound. Either way the failure
					// worth reporting is the one below, not this.
				}
			}

			// The unique index on (kind, reference) is what stops a webhook
			// delivered twice from crediting twice.
			if ( '' !== $reference && false !== stripos( $e->getMessage(), 'UNIQUE' ) ) {
				throw new \RuntimeException( 'This payment has already been recorded.', 409, $e );
			}

			throw $e;
		}
	}

	public static function credit( int $userId, int $cents, string $kind, string $note = '', string $reference = '', ?int $by = null ): int {
		return self::post( $userId, abs( $cents ), $kind, $note, $reference, $by );
	}

	public static function debit( int $userId, int $cents, string $kind, string $note = '', string $reference = '', ?int $by = null ): int {
		return self::post( $userId, -abs( $cents ), $kind, $note, $reference, $by );
	}

	public static function hasReference( string $kind, string $reference ): bool {
		if ( '' === $reference ) {
			return false;
		}

		$statement = Database::pdo()->prepare(
			'SELECT COUNT(*) FROM wallet_entries WHERE kind = ? AND reference = ?'
		);

		$statement->execute( array( $kind, $reference ) );

		return (int) $statement->fetchColumn() > 0;
	}

	/**
	 * Total held across all members. Useful for reconciling against Zeffy.
	 */
	public static function totalOutstanding(): int {
		return (int) Database::pdo()
			->query( 'SELECT COALESCE(SUM(delta_cents), 0) FROM wallet_entries' )
			->fetchColumn();
	}

	public static function humanKind( string $kind ): string {
		return match ( $kind ) {
			self::KIND_TOPUP      => 'Top-up',
			self::KIND_ORDER      => 'Order',
			self::KIND_REFUND     => 'Refund',
			self::KIND_ADJUSTMENT => 'Adjustment',
			self::KIND_ZEFFY      => 'Zeffy payment',
			default               => ucfirst( $kind ),
		};
	}
}
