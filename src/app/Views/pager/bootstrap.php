<?php

use CodeIgniter\Pager\PagerRenderer;

/** @var PagerRenderer $pager */
?>

<?php if ($pager->getTotal() > 0) : ?>
    <nav aria-label="Pagination">
        <ul class="pagination pagination-sm m-0 justify-content-end">
            <?php if ($pager->hasPreviousPage()) : ?>
                <li class="page-item">
                    <a class="page-link" href="<?= $pager->getPreviousPage() ?>" aria-label="Previous">
                        <span aria-hidden="true">&laquo;</span>
                    </a>
                </li>
            <?php else : ?>
                <li class="page-item disabled"><span class="page-link">&laquo;</span></li>
            <?php endif ?>

            <?php foreach ($pager->links() as $link) : ?>
                <li class="page-item <?= $link['active'] ? 'active' : '' ?>">
                    <a class="page-link" href="<?= $link['uri'] ?>"><?= esc($link['title']) ?></a>
                </li>
            <?php endforeach ?>

            <?php if ($pager->hasNextPage()) : ?>
                <li class="page-item">
                    <a class="page-link" href="<?= $pager->getNextPage() ?>" aria-label="Next">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
            <?php else : ?>
                <li class="page-item disabled"><span class="page-link">&raquo;</span></li>
            <?php endif ?>
        </ul>
    </nav>
<?php endif ?>
