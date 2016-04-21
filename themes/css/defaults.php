<?php

/**
 * Class OC_Theme
 *
 * Theme class for ownCloud.
 * @see  https://doc.owncloud.org/server/9.0/developer_manual/core/theming.html for more information
 */
class OC_Theme
{
    /**
     * Can be a longer name, for titles
     * @var string
     */
    private $title;

    /**
     * Company name, used for footers and copyright notices
     * @var string
     */
    private $entity;

    /**
     * Short name, used when referring to the software
     * @var string
     */
    private $name;

    /**
     * Sets all of the variables
     * OC_Theme constructor.
     */
    public function __construct()
    {
        $this->title = 'CSS';
        $this->entity = 'CSS Calendar';
        $this->name = 'CSS';
    }

    /**
     * Returns the entity string
     * @return string
     */
    public function getEntity()
    {
        return $this->entity;
    }

    /**
     * Returns the name string
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Returns the title string
     * @return string
     */
    public function getTitle()
    {
        return $this->title;
    }
}