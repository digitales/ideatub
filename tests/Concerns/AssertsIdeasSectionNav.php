<?php

namespace Tests\Concerns;

use DOMDocument;
use DOMXPath;
use Illuminate\Testing\TestResponse;

trait AssertsIdeasSectionNav
{
    protected function assertIdeasSectionNav(TestResponse $response, string $active): void
    {
        $response->assertSee('data-testid="ideas-section-nav"', false);

        $html = $response->getContent();
        libxml_use_internal_errors(true);

        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        $xpath = new DOMXPath($dom);
        $navNodes = $xpath->query('//*[@data-testid="ideas-section-nav"]');
        $this->assertSame(1, $navNodes->length);

        $nav = $navNodes->item(0);
        $ideasLink = $xpath->query(".//a[@href='".route('idea.ideas')."']", $nav)->item(0);
        $revisitLink = $xpath->query(".//a[@href='".route('idea.revisit')."']", $nav)->item(0);
        $completedLink = $xpath->query(".//a[@href='".route('idea.completed')."']", $nav)->item(0);

        $this->assertNotNull($ideasLink);
        $this->assertNotNull($revisitLink);
        $this->assertNotNull($completedLink);
        $this->assertSame($active === 'ideas' ? 'page' : '', $ideasLink->getAttribute('aria-current'));
        $this->assertSame($active === 'revisit' ? 'page' : '', $revisitLink->getAttribute('aria-current'));
        $this->assertSame($active === 'completed' ? 'page' : '', $completedLink->getAttribute('aria-current'));
    }
}
