<?php $pager->setSurroundCount(2) ?>

<nav aria-label="Navegação de página" class="mt-4">
    <ul class="pagination pagination-sm justify-content-center gap-1">
    <?php if ($pager->hasPrevious()) : ?>
        <li class="page-item">
            <a class="page-link border-0 rounded-circle shadow-sm px-3 py-2 text-dark" href="<?= $pager->getFirst() ?>" aria-label="<?= lang('Pager.first') ?>">
                <span aria-hidden="true"><i class="fa-solid fa-angles-left small"></i></span>
            </a>
        </li>
        <li class="page-item">
            <a class="page-link border-0 rounded-circle shadow-sm px-3 py-2 text-dark" href="<?= $pager->getPrevious() ?>" aria-label="<?= lang('Pager.previous') ?>">
                <span aria-hidden="true"><i class="fa-solid fa-angle-left small"></i></span>
            </a>
        </li>
    <?php endif ?>

    <?php foreach ($pager->links() as $link): ?>
        <li class="page-item <?= $link['active'] ? 'active' : '' ?>">
            <a class="page-link border-0 rounded-circle shadow-sm px-3 py-2 <?= $link['active'] ? 'bg-primary text-white' : 'bg-white text-dark' ?>" href="<?= $link['uri'] ?>">
                <?= $link['title'] ?>
            </a>
        </li>
    <?php endforeach ?>

    <?php if ($pager->hasNext()) : ?>
        <li class="page-item">
            <a class="page-link border-0 rounded-circle shadow-sm px-3 py-2 text-dark" href="<?= $pager->getNext() ?>" aria-label="<?= lang('Pager.next') ?>">
                <span aria-hidden="true"><i class="fa-solid fa-angle-right small"></i></span>
            </a>
        </li>
        <li class="page-item">
            <a class="page-link border-0 rounded-circle shadow-sm px-3 py-2 text-dark" href="<?= $pager->getLast() ?>" aria-label="<?= lang('Pager.last') ?>">
                <span aria-hidden="true"><i class="fa-solid fa-angles-right small"></i></span>
            </a>
        </li>
    <?php endif ?>
    </ul>
</nav>
