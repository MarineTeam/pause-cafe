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

		if ( $ownTransaction ) {
			$pdo->beginTransaction();
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
				$pdo->commit();
			}

			return $balance;
		} catch ( PDOException $e ) {
			if ( $ownTransaction && $pdo->inTransaction() ) {
				$pdo->rollBack();
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
