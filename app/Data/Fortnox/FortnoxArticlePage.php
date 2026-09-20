<?php

namespace App\Data\Fortnox;

class FortnoxArticlePage
{
    /**
     * @param  list<FortnoxArticle>  $articles
     */
    public function __construct(
        public readonly array $articles,
        public readonly ?string $nextPage,
    ) {}
}
