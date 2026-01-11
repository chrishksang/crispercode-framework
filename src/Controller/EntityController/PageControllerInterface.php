<?php

namespace CrisperCode\Controller\EntityController;

use CrisperCode\Entity\EntityInterface;

interface PageControllerInterface
{
    public function getPageTitle(?EntityInterface $entity): string;

    public function getPageTemplate(): string;
}
