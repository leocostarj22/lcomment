<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Table;

\defined('_JEXEC') or die;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;

final class CommentTable extends Table
{
    public function __construct(DatabaseInterface $db)
    {
        parent::__construct('#__lcomment_comments', 'id', $db);
    }

    public function check(): bool
    {
        if (trim((string) $this->comment_text) === '') {
            $this->setError('COM_LCOMMENT_ERROR_TEXT_REQUIRED');

            return false;
        }

        if (empty($this->created)) {
            $this->created = \Joomla\CMS\Factory::getDate()->toSql();
        }

        if (empty($this->language)) {
            $this->language = '*';
        }

        return true;
    }
}
