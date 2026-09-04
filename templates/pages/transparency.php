<h1><?= _h('transparency.h1') ?></h1>
<p><?= _h('transparency.intro') ?></p>

<div id="transparency-loading" class="transparency-loading"><?= _h('transparency.loading') ?></div>
<div id="transparency-content" style="display:none">
    <div class="transparency-summary card" id="trans-summary"></div>
    <div class="transparency-table-wrap">
    <table class="transparency-table" id="trans-table">
        <thead>
            <tr>
                <th>#</th>
                <th class="trans-sortable" data-sort="company" data-exclusive="representative"><?= _h('transparency.company') ?> <i class="bi bi-arrow-down-up trans-sort-icon"></i></th>
                <th class="trans-sortable" data-sort="representative" data-exclusive="company"><?= _h('transparency.represented') ?> <i class="bi bi-arrow-down-up trans-sort-icon"></i></th>
                <th class="trans-sortable" data-sort="total"><?= _h('transparency.total') ?> <i class="bi bi-arrow-down-up trans-sort-icon"></i></th>
                <th class="trans-sortable" data-sort="accepted"><?= _h('transparency.reviewed') ?> <i class="bi bi-arrow-down-up trans-sort-icon"></i></th>
                <th class="trans-sortable" data-sort="blocked"><?= _h('transparency.blocked') ?> <i class="bi bi-arrow-down-up trans-sort-icon"></i></th>
                <th class="trans-sortable" data-sort="pending"><?= _h('transparency.pending') ?> <i class="bi bi-arrow-down-up trans-sort-icon"></i></th>
            </tr>
        </thead>
        <tbody id="trans-body"></tbody>
    </table>
    </div>
    <div class="trans-pagination" id="trans-pagination"></div>
</div>
