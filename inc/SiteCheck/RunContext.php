<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

/**
 * The issued run's settings snapshot, built once at run start and handed to
 * every check. Immutable: no check reads live settings during a run.
 */
final class RunContext
{
    private string $run;
    private string $profile;
    /** @var list<string> */
    private array $sitemap_allow;
    private string $fallback_theme;
    private bool $probe;

    /**
     * @param list<string> $sitemap_allow
     */
    public function __construct(
        string $run,
        string $profile,
        array $sitemap_allow,
        string $fallback_theme,
        bool $probe
    ) {
        $this->run = $run;
        $this->profile = $profile;
        $this->sitemap_allow = $sitemap_allow;
        $this->fallback_theme = $fallback_theme;
        $this->probe = $probe;
    }

    public function run(): string
    {
        return $this->run;
    }

    public function profile(): string
    {
        return $this->profile;
    }

    /** @return list<string> */
    public function sitemap_allow(): array
    {
        return $this->sitemap_allow;
    }

    public function fallback_theme(): string
    {
        return $this->fallback_theme;
    }

    /** Consent to the uploads probe for this run. */
    public function probe(): bool
    {
        return $this->probe;
    }
}
