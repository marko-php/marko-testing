<?php

declare(strict_types=1);

namespace Marko\Testing\Tests\DatabaseApp\Entity;

use Marko\Database\Attributes\Column;
use Marko\Database\Attributes\Table;
use Marko\Database\Entity\Entity;

#[Table('shows')]
class Show extends Entity
{
    #[Column(primaryKey: true, autoIncrement: true)]
    public ?int $id = null;
}
