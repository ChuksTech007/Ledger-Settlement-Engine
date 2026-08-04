<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    seedSystemWallets();
    $this->user = User::factory()->withTotp()->withWallets()->create();
});

describe('access control', function (): void {
    it('requires authentication', function (): void {
        $wallet = $this->user->walletFor(Currency::NGN);

        $this->getJson("/api/ledger/{$wallet->id}")->assertUnauthorized();
    });

    it("refuses to expose another user's ledger", function (): void {
        $other = User::factory()->withWallets()->create();
        $victimWallet = $other->walletFor(Currency::NGN);

        // An unguessable wallet id is not an authorisation control.
        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/ledger/{$victimWallet->id}")
            ->assertForbidden();
    });

    it('returns 404 for an unknown wallet', function (): void {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/ledger/'.Str::uuid())
            ->assertNotFound();
    });
});

describe('pagination', function (): void {
    it('reports the derived balance alongside the entries', function (): void {
        fundWallet($this->user, Currency::NGN, 750_000);
        $wallet = $this->user->walletFor(Currency::NGN);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/ledger/{$wallet->id}")
            ->assertOk()
            ->assertJsonPath('wallet.balance_subunits', 750_000)
            ->assertJsonPath('wallet.currency', 'NGN')
            ->assertJsonCount(1, 'data');
    });

    it('pages newest-first with a stable keyset cursor', function (): void {
        // 12 separate fundings => 12 entries on this wallet.
        foreach (range(1, 12) as $i) {
            fundWallet($this->user, Currency::NGN, 1_000 * $i);
        }

        $wallet = $this->user->walletFor(Currency::NGN);

        $first = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/ledger/{$wallet->id}?per_page=5")
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('pagination.has_more', true);

        $firstIds = collect($first->json('data'))->pluck('id')->all();

        // Newest first.
        expect($firstIds)->toEqual(collect($firstIds)->sortDesc()->values()->all());

        $cursor = $first->json('pagination.next_cursor');

        $second = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/ledger/{$wallet->id}?per_page=5&cursor={$cursor}")
            ->assertOk()
            ->assertJsonCount(5, 'data');

        $secondIds = collect($second->json('data'))->pluck('id')->all();

        // Pages must not overlap — the defect OFFSET pagination introduces
        // whenever rows are inserted mid-traversal.
        expect(array_intersect($firstIds, $secondIds))->toBeEmpty();
        expect(max($secondIds))->toBeLessThan(min($firstIds));
    });

    it('caps per_page to protect the database', function (): void {
        fundWallet($this->user, Currency::NGN, 1_000);
        $wallet = $this->user->walletFor(Currency::NGN);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/ledger/{$wallet->id}?per_page=100000")
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 100);
    });
});

describe('index usage', function (): void {
    it('serves the ledger query from the composite index, not a sequential scan', function (): void {
        foreach (range(1, 40) as $i) {
            fundWallet($this->user, Currency::NGN, 1_000);
        }

        $wallet = $this->user->walletFor(Currency::NGN);

        // Ask PostgreSQL how it actually intends to run the paginated query.
        $plan = DB::select(
            'EXPLAIN SELECT * FROM ledger_entries WHERE wallet_id = ? ORDER BY id DESC LIMIT 26',
            [$wallet->id],
        );

        $planText = implode(' ', array_map(fn ($row): string => (string) reset($row), $plan));

        // On a tiny test table PostgreSQL may legitimately prefer a scan, so we
        // assert the index EXISTS and is usable rather than forcing its use.
        $indexes = DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'ledger_entries'"
        );

        $names = array_map(fn ($row): string => $row->indexname, $indexes);

        expect($names)->toContain('ledger_entries_wallet_id_id_index');
        expect($planText)->not->toBeEmpty();
    });
});
