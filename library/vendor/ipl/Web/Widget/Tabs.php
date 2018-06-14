<?php

namespace dipl\Web\Widget;

use dipl\Html\ValidHtml;
use Icinga\Exception\ProgrammingError;
use Icinga\Web\Widget\Tabs as WebTabs;
use RuntimeException;

class Tabs extends WebTabs implements ValidHtml
{
    /**
     * @param string $name
     * @param array|\Icinga\Web\Widget\Tab $tab
     * @return $this
     */
    public function add($name, $tab)
    {
        try {
            parent::add($name, $tab);
        } catch (ProgrammingError $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        return $this;
    }
}
