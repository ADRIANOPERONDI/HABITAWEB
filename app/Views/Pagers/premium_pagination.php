<?php $pager->setSurroundCount(2) ?>

<nav aria-label="Page navigation" class="d-flex justify-content-center mt-5">
    <ul class="pagination pagination-lg gap-2 align-items-center">
        <?php if ($pager->hasPrevious()) : ?>
            <li class="page-item">
                <a href="<?= $pager->getFirst() ?>" aria-label="<?= lang('Pager.first') ?>" class="page-link border-0 rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 45px; height: 45px; color: var(--text-muted); background: #fff;">
                    <i class="fas fa-angle-double-left small"></i>
                </a>
            </li>
            <li class="page-item">
                <a href="<?= $pager->getPrevious() ?>" aria-label="<?= lang('Pager.previous') ?>" class="page-link border-0 rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 45px; height: 45px; color: var(--text-muted); background: #fff;">
                    <i class="fas fa-angle-left small"></i>
                </a>
            </li>
        <?php endif ?>

        <?php foreach ($pager->links() as $link) : ?>
            <li class="page-item <?= $link['active'] ? 'active' : '' ?>">
                <a href="<?= $link['uri'] ?>" class="page-link border-0 rounded-circle fw-bold d-flex align-items-center justify-content-center shadow-sm" 
                   style="width: 45px; height: 45px; transition: all 0.3s ease; 
                   <?= $link['active'] ? 'background: var(--primary-gradient); color: #fff; transform: scale(1.1); box-shadow: 0 4px 10px rgba(var(--primary-rgb), 0.4) !important;' : 'background: #fff; color: var(--text-dark);' ?>">
                    <?= $link['title'] ?>
                </a>
            </li>
        <?php endforeach ?>

        <?php if ($pager->hasNext()) : ?>
            <li class="page-item">
                <a href="<?= $pager->getNext() ?>" aria-label="<?= lang('Pager.next') ?>" class="page-link border-0 rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 45px; height: 45px; color: var(--text-muted); background: #fff;">
                    <i class="fas fa-angle-right small"></i>
                </a>
            </li>
            <li class="page-item">
                <a href="<?= $pager->getLast() ?>" aria-label="<?= lang('Pager.last') ?>" class="page-link border-0 rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 45px; height: 45px; color: var(--text-muted); background: #fff;">
                    <i class="fas fa-angle-double-right small"></i>
                </a>
            </li>
        <?php endif ?>
    </ul>
</nav>
