<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\CommandResult;
use App\Exceptions\Financial\FinancialIntegrityException;
use App\Exceptions\Financial\UserFinancialException;
use App\Models\BudgetPeriod;
use App\Models\CategoryKeyword;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class CommandParserService
{
    public function __construct(
        private readonly FinancialEngineService $financialEngine,
        private readonly ReportingService $reporting,
        private readonly RupiahFormatter $rupiah,
    ) {}

    public function handleIncomingMessage(int $userId, string $text): CommandResult
    {
        $normalizedText = Str::of($text)->trim()->value();

        if ($normalizedText === '') {
            return new CommandResult('❌ Pesan tidak boleh kosong.');
        }

        try {
            if (Str::startsWith($normalizedText, '/')) {
                return $this->parseCommand($userId, $normalizedText);
            }

            return $this->parseNaturalExpense($userId, $normalizedText);
        } catch (InvalidArgumentException|UserFinancialException $exception) {
            return $this->mapExpectedException($exception);
        }
    }

    private function parseCommand(int $userId, string $text): CommandResult
    {
        $matched = preg_match(
            '/^\/([a-zA-Z0-9_]+)(?:@[A-Za-z0-9_]+)?(?:\s+(.*))?$/us',
            $text,
            $matches,
        );

        if ($matched !== 1) {
            return $this->unknownCommand();
        }

        $command = Str::lower($matches[1]);
        $arguments = isset($matches[2])
            ? Str::of($matches[2])->trim()->value()
            : '';

        return match ($command) {
            'gajian' => $this->handleGajian($userId, $arguments),
            'masuk' => $this->handleIncome($userId, $arguments),
            'tagihan' => $this->handleInstallment($userId, '/tagihan '.$arguments),
            'tagihan_hapus' => $this->handleDeleteInstallment($userId, '/tagihan_hapus '.$arguments),
            'ambil_dingin' => $this->handleColdMoneyTransfer($userId, $arguments),
            'undo' => $this->handleUndo($userId, $arguments),
            'undo_gajian' => $this->handleUndoGajian($userId, $arguments),
            'input' => $this->parseNaturalExpense($userId, $arguments),
            'status' => $this->handleStatus($userId, $arguments),
            'recap' => $this->handleRecap($userId, $arguments),
            'proyeksi' => $this->handleProyeksi($userId, $arguments),
            'export' => $this->handleExport($userId, $arguments),
            default => $this->unknownCommand(),
        };
    }

    private function handleGajian(int $userId, string $arguments): CommandResult
    {
        $amount = $this->parseSingleMoneyArgument($arguments);
        $user = $this->findUser($userId);
        $localToday = $this->localToday($user)->toDateString();

        $cycle = $this->financialEngine->processGajian(
            $userId,
            $amount,
            $localToday,
        );

        $activePeriod = BudgetPeriod::query()
            ->where('budget_cycle_id', $cycle->getKey())
            ->where('start_date', '<=', $localToday)
            ->where('end_date', '>=', $localToday)
            ->first();

        if (! $activePeriod instanceof BudgetPeriod) {
            throw new RuntimeException('The active salary period is missing after processing.');
        }

        $wallet = $this->findWallet($userId);

        return new CommandResult(
            "✅ Gajian berhasil diproses!\n\n".
            "Gaji: {$this->rupiah->format($cycle->gross_income)}\n".
            "Cicilan: {$this->rupiah->format($cycle->total_installments)}\n".
            "Gaji bersih: {$this->rupiah->format($cycle->net_income)}\n".
            "Jatah minggu ini: {$this->rupiah->format($activePeriod->total_budget)}\n".
            "Uang dingin: {$this->rupiah->format($wallet->uang_dingin)}"
        );
    }

    private function handleIncome(int $userId, string $arguments): CommandResult
    {
        [$amount, $description] = $this->parseAmountAndDescription($arguments);

        $transaction = $this->financialEngine->recordIncome(
            $userId,
            $amount,
            $description,
        );

        $wallet = $this->findWallet($userId);

        return new CommandResult(
            "✅ Pemasukan {$this->rupiah->format($transaction->amount)} berhasil dicatat.\n\n".
            "Uang dingin: {$this->rupiah->format($wallet->uang_dingin)}"
        );
    }

    private function handleInstallment(int $userId, string $text): CommandResult
    {
        $matched = preg_match('/^\/tagihan\s+(.+?)\s+(\d+)\s+(\d+)$/i', $text, $matches);

        if ($matched !== 1) {
            throw new InvalidArgumentException('Invalid installment syntax.');
        }

        $name = trim($matches[1]);
        $amount = $this->parseMoney($matches[2]);
        $remainingTenor = (int) $matches[3];

        if ($name === '' || $remainingTenor <= 0) {
            throw new InvalidArgumentException('Installment name is required.');
        }

        $installment = $this->financialEngine->upsertFixedInstallment(
            $userId,
            $name,
            $amount,
            $remainingTenor,
        );

        return new CommandResult(
            "✅ Tagihan {$installment->name} berhasil disimpan.\n\n".
            "Nominal: {$this->rupiah->format($amount)}"
        );
    }

    private function handleDeleteInstallment(int $userId, string $text): CommandResult
    {
        $matched = preg_match('/^\/tagihan_hapus\s+(.+)$/i', $text, $matches);

        if ($matched !== 1) {
            throw new InvalidArgumentException('Invalid installment deletion syntax.');
        }

        $name = trim($matches[1]);

        if ($name === '') {
            throw new InvalidArgumentException('Installment name is required.');
        }

        $installment = $this->financialEngine->deactivateInstallment($userId, $name);

        if ($installment === null) {
            return new CommandResult("❌ Tagihan {$name} tidak ditemukan.");
        }

        return new CommandResult("✅ Tagihan {$installment->name} berhasil dihapus.");
    }

    private function handleColdMoneyTransfer(int $userId, string $arguments): CommandResult
    {
        $amount = $this->parseSingleMoneyArgument($arguments);

        $transaction = $this->financialEngine->transferAmbilDingin($userId, $amount);
        $wallet = $this->findWallet($userId);

        return new CommandResult(
            "✅ {$this->rupiah->format($transaction->amount)} dipindahkan dari uang dingin.\n\n".
            "Uang dingin tersisa: {$this->rupiah->format($wallet->uang_dingin)}\n".
            "Jatah aktif sekarang: {$this->rupiah->format($wallet->dompet_jajan_aktif)}"
        );
    }

    private function handleUndo(int $userId, string $arguments): CommandResult
    {
        $this->requireNoArguments($arguments);

        $this->financialEngine->undoLastTransaction($userId);
        $wallet = $this->findWallet($userId);

        return new CommandResult(
            "✅ Transaksi terakhir berhasil dibatalkan.\n\n".
            "Uang dingin: {$this->rupiah->format($wallet->uang_dingin)}\n".
            "Jatah aktif: {$this->rupiah->format($wallet->dompet_jajan_aktif)}"
        );
    }

    private function handleUndoGajian(int $userId, string $arguments): CommandResult
    {
        $this->requireNoArguments($arguments);

        $this->financialEngine->undoGajian($userId);
        $wallet = $this->findWallet($userId);

        return new CommandResult(
            "✅ Gajian berhasil dibatalkan.\n\n".
            "Uang dingin: {$this->rupiah->format($wallet->uang_dingin)}\n".
            "Jatah aktif: {$this->rupiah->format($wallet->dompet_jajan_aktif)}"
        );
    }

    private function parseNaturalExpense(int $userId, string $text): CommandResult
    {
        [$amount, $description] = $this->parseAmountAndDescription($text);
        $matchedKeyword = $this->findBestCategoryKeyword($description);

        $transaction = $this->financialEngine->recordExpense(
            $userId,
            $amount,
            $description,
            $matchedKeyword,
        );

        return new CommandResult($this->reporting->getSmartGuideResponse($userId, $transaction));
    }

    private function handleStatus(int $userId, string $arguments): CommandResult
    {
        $this->requireNoArguments($arguments);

        return new CommandResult($this->reporting->handleStatus($userId));
    }

    private function handleRecap(int $userId, string $arguments): CommandResult
    {
        $type = $arguments === '' ? 'bulan' : Str::lower($arguments);

        return new CommandResult($this->reporting->handleRecap($userId, $type));
    }

    private function handleProyeksi(int $userId, string $arguments): CommandResult
    {
        $this->requireNoArguments($arguments);

        return new CommandResult($this->reporting->handleProyeksi($userId));
    }

    private function handleExport(int $userId, string $arguments): CommandResult
    {
        $this->requireNoArguments($arguments);
        $export = $this->reporting->handleExport($userId);

        return new CommandResult(
            text: '✅ Export transaksi siap.',
            documentPath: $export->absolutePath,
            documentName: $export->filename,
            documentMimeType: $export->mimeType,
        );
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function parseAmountAndDescription(string $value): array
    {
        $matched = preg_match('/^(\S+)\s+(.+)$/us', Str::of($value)->trim()->value(), $matches);

        if ($matched !== 1) {
            throw new InvalidArgumentException('Amount and description are required.');
        }

        $amount = $this->parseMoney($matches[1]);
        $description = Str::of($matches[2])->trim()->squish()->value();

        if ($description === '') {
            throw new InvalidArgumentException('Description is required.');
        }

        return [$amount, $description];
    }

    private function parseSingleMoneyArgument(string $value): int
    {
        $normalizedValue = Str::of($value)->trim()->value();

        if ($normalizedValue === '' || preg_match('/\s/u', $normalizedValue) === 1) {
            throw new InvalidArgumentException('A single amount is required.');
        }

        return $this->parseMoney($normalizedValue);
    }

    private function parseMoney(string $value): int
    {
        $normalizedValue = Str::of($value)->trim()->value();

        $isPlainInteger = preg_match('/^[1-9][0-9]*$/', $normalizedValue) === 1;
        $isGroupedInteger = preg_match(
            '/^[1-9][0-9]{0,2}([.,])[0-9]{3}(?:\\1[0-9]{3})*$/',
            $normalizedValue,
        ) === 1;

        if (! $isPlainInteger && ! $isGroupedInteger) {
            throw new InvalidArgumentException('Amount format is invalid.');
        }

        $digits = str_replace(['.', ','], '', $normalizedValue);
        $maximumInteger = (string) PHP_INT_MAX;

        if (
            Str::length($digits) > Str::length($maximumInteger)
            || (
                Str::length($digits) === Str::length($maximumInteger)
                && strcmp($digits, $maximumInteger) > 0
            )
        ) {
            throw new InvalidArgumentException('Amount exceeds the supported integer range.');
        }

        $amount = (int) $digits;

        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        return $amount;
    }

    private function findBestCategoryKeyword(string $description): ?string
    {
        $normalizedDescription = CategoryKeyword::normalizeKeyword($description);

        $bestKeyword = null;
        $bestLength = 0;

        $keywords = CategoryKeyword::query()
            ->orderBy('id')
            ->pluck('normalized_keyword');

        foreach ($keywords as $keyword) {
            if (! is_string($keyword)) {
                continue;
            }

            $normalizedKeyword = CategoryKeyword::normalizeKeyword($keyword);

            if ($normalizedKeyword === '') {
                continue;
            }

            $matched = preg_match(
                '/(?<![\\p{L}\\p{N}_])'.preg_quote($normalizedKeyword, '/').'(?![\\p{L}\\p{N}_])/iu',
                $normalizedDescription,
            );

            if ($matched === false) {
                throw new RuntimeException('Category keyword matching failed.');
            }

            $keywordLength = Str::length($normalizedKeyword);

            if ($matched === 1 && $keywordLength > $bestLength) {
                $bestKeyword = $normalizedKeyword;
                $bestLength = $keywordLength;
            }
        }

        return $bestKeyword;
    }

    private function findUser(int $userId): User
    {
        $user = User::query()->find($userId);

        if (! $user instanceof User) {
            throw new FinancialIntegrityException('User not found.');
        }

        return $user;
    }

    private function localToday(User $user): CarbonImmutable
    {
        try {
            return CarbonImmutable::now($user->timezone)->startOfDay();
        } catch (\Throwable $exception) {
            throw new RuntimeException('The user timezone is invalid.', 0, $exception);
        }
    }

    private function findWallet(int $userId): Wallet
    {
        $wallet = Wallet::query()
            ->where('user_id', $userId)
            ->first();

        if (! $wallet instanceof Wallet) {
            throw new RuntimeException('Wallet cache is missing after financial processing.');
        }

        return $wallet;
    }

    private function requireNoArguments(string $arguments): void
    {
        if ($arguments !== '') {
            throw new InvalidArgumentException('This command does not accept arguments.');
        }
    }

    private function mapExpectedException(
        InvalidArgumentException|UserFinancialException $exception
    ): CommandResult {
        $message = $exception->getMessage();

        if (Str::contains($message, ['No active budget cycle', 'No active salary cycle'])) {
            return new CommandResult('❌ Belum ada siklus gajian aktif.');
        }

        if (Str::contains($message, 'Insufficient uang_dingin')) {
            return new CommandResult('❌ Uang dingin tidak mencukupi.');
        }

        if (Str::contains($message, ['Pay date must be the 25th', 'Pay date must match'])) {
            return new CommandResult('❌ /gajian hanya bisa diproses tanggal 25.');
        }

        if (Str::contains($message, 'No undoable transaction')) {
            return new CommandResult('❌ Tidak ada transaksi yang bisa dibatalkan.');
        }

        if (Str::contains($message, 'later transaction exists after the salary batch', true)) {
            return new CommandResult('❌ Gajian tidak bisa dibatalkan karena sudah ada transaksi setelahnya.');
        }

        if (Str::contains($message, 'not fixed')) {
            return new CommandResult('❌ Nama tagihan tersebut sudah digunakan untuk cicilan revolving.');
        }

        if ($exception instanceof InvalidArgumentException) {
            return new CommandResult('❌ Format command tidak valid.');
        }

        return new CommandResult('❌ Data keuangan tidak dapat diproses.');
    }

    private function unknownCommand(): CommandResult
    {
        return new CommandResult(
            "❌ Command tidak dikenal.\n\n".
            "Command tersedia:\n".
            "/gajian\n".
            "/masuk\n".
            "/tagihan\n".
            "/tagihan_hapus\n".
            "/ambil_dingin\n".
            "/undo\n".
            "/undo_gajian\n".
            "/status\n".
            "/recap\n".
            "/proyeksi\n".
            "/export\n\n".
            "Atau langsung ketik:\n".
            '15000 bakso'
        );
    }
}
