<?php

namespace App\Actions;

use App\Enums\TimesheetCoordinates;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class SignPdfAction
{
    protected ?string $id = null;

    protected User|Employee|null $user = null;

    protected ?string $python;

    protected array|string|null $pyhanko;

    protected string $path = '';

    protected ?string $out = null;

    protected string $field = 'Signature';

    protected ?string $coordinates = null;

    protected int $page = 1;

    protected array $data = [];

    protected bool $certify = false;

    public function __construct()
    {
        $this->python = trim(`which python3` ?? `which python`);

        if ($this->python === null) {
            throw new RuntimeException('Python interpreter required');
        }

        $this->pyhanko = trim(`pyhanko --version`) ? 'pyhanko' : (
            trim(`{$this->python} -m pyhanko --version`) !== null
                ? ["{$this->python}", '-m', 'pyhanko']
                : null
        );

        if ($this->pyhanko === null) {
            throw new RuntimeException('PyHanko module is not found or installed');
        }

        $this->id = mb_strtolower(str()->ulid());
    }

    public function __invoke(
        User|Employee|null $user,
        string $path,
        ?string $out,
        string $field,
        TimesheetCoordinates|string|null $coordinates = null,
        int $page = 1,
        array $data = [],
        bool $certify = false,
        ?string $certificate = null,
        ?string $specimen = null,
        ?string $password = null,
    ): void {
        $this->field($field)
            ->coordinates($coordinates)
            ->page($page)
            ->data($data)
            ->certify($certify)
            ->sign($user, $path, $out, $certificate, $specimen, $password);
    }

    public function sign(
        User|Employee|null $user,
        string $path,
        ?string $out = null,
        ?string $certificate = null,
        ?string $specimen = null,
        ?string $password = null,
    ): void {
        if ($user !== null && $user?->signature === null) {
            throw new RuntimeException('User signature is not yet configured');
        }

        $this->user = $user;

        $this->path = $path;

        $this->out = $out;

        try {
            $directory = storage_path('signing/'.str($this->id())->slug('_')->append('/'));

            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            if ($user) {
                file_put_contents($directory.'certificate.pfx', base64_decode($user->signature->certificateBase64));
                file_put_contents($directory.'signature.webp', base64_decode($user->signature->specimenBase64));
            } elseif ($certificate && $specimen) {
                if (file_exists($certificate) && file_exists($specimen)) {
                    rename($certificate, $directory.'certificate.pfx');
                    rename($specimen, $directory.'signature.webp');
                } else {
                    file_put_contents($directory.'certificate.pfx', $certificate);
                    file_put_contents($directory.'signature.webp', $specimen);
                }
            }

            if (! is_null($this->coordinates) && ! is_null($this->page)) {
                if (file_exists($directory.'pyhanko.yml')) {
                    unlink($directory.'pyhanko.yml');
                }

                file_put_contents($directory.'pyhanko.yml', isset($this->data['yml']) ? $this->data['yml'] : $this->yml());
            }

            file_put_contents($directory.'password', $user?->signature->password ?? $password);

            $timestamp = env('TIMESTAMP_URL') !== null;

            do {
                $process = Process::timeout(30)
                    ->path($directory)
                    ->run($this->command($timestamp));

                if ($process->failed()) {
                    if ($timestamp && str($process->errorOutput())->lower()->contains('timestamp')) {
                        $timestamp = false;

                        continue;
                    }

                    throw new RuntimeException($process->errorOutput());
                }

                break;
            } while (1);
        } finally {
            if ($directory && is_dir($directory)) {
                Process::run(['rm', '-rf', $directory]);
            }
        }
    }

    public function field(string $field): static
    {
        $this->field = $field;

        return $this;
    }

    public function coordinates(TimesheetCoordinates|string|null $coordinates): static
    {
        $this->coordinates = $coordinates instanceof TimesheetCoordinates ? $coordinates->value : $coordinates;

        return $this;
    }

    public function page(int $page): static
    {
        $this->page = $page;

        return $this;
    }

    public function data(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function certify(bool $certify): static
    {
        $this->certify = $certify;

        return $this;
    }

    public function command(bool $timestamp = true): array
    {
        $field = ! is_null($this->coordinates) && ! is_null($this->page)
            ? "{$this->page}/{$this->coordinates}/{$this->field}"
            : $this->field;

        $command = [
            ...(is_string($this->pyhanko) ? [$this->pyhanko] : $this->pyhanko),
            '--verbose',
            'sign',
            'addsig',
        ];

        if ($field) {
            $command[] = '--field='.$field;
        }

        if ($this->certify) {
            $command[] = '--certify';
        }

        if ($timestamp && env('TIMESTAMP_URL')) {
            $command[] = '--timestamp-url='.env('TIMESTAMP_URL');
        }

        if (isset($this->data['reason'])) {
            $command[] = "--reason={$this->data['reason']}";
        }

        return array_merge($command, [
            '--contact-info='.($this->data['contact'] ?? $this->user?->email),
            '--location='.($this->data['location'] ?? 'Philippines'),
            'pkcs12',
            '--passfile=password',
            $this->path,
            $this->out ?? $this->path,
            'certificate.pfx',
        ]);
    }

    public function id(): string
    {
        if ($this->user === null) {
            return "{$this->id}-{$this->path}";
        }

        return "{$this->user->id}-{$this->path}";
    }

    public function yml(): string
    {
        return <<<'YML'
        stamp-styles:
          default:
            type: text
            stamp-text: "Signed by %(signer)s\nTimestamp: %(ts)s"
            background: "signature.webp"
            background-opacity: 1
            border-width: 0
            inner-content-layout:
              y-align: bottom
        YML;
    }
}
