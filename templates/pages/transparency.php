<h1><?= _h('transparency.h1') ?></h1>
<p><?= _h('transparency.intro') ?></p>

<div id="transparency-loading" class="transparency-loading"><?= _h('transparency.loading') ?></div>
<div id="transparency-content" style="display:none">
    <div class="transparency-summary card" id="trans-summary"></div>
    <div class="transparency-table-wrap">
    <table class="transparency-table" id="trans-table">
        <thead>
            <tr>
                <?php /* The sortable headers' words are buttons (1.74.0): the keyboard reaches them, Enter sorts. */ ?>
                <th>#</th>
                <th class="trans-sortable" data-sort="company" data-exclusive="representative"><button type="button" class="th-sort"><?= _h('transparency.company') ?> <i class="bi bi-arrow-down-up trans-sort-icon" aria-hidden="true"></i></button></th>
                <th class="trans-sortable" data-sort="representative" data-exclusive="company"><button type="button" class="th-sort"><?= _h('transparency.represented') ?> <i class="bi bi-arrow-down-up trans-sort-icon" aria-hidden="true"></i></button></th>
                <th class="trans-sortable" data-sort="total"><button type="button" class="th-sort"><?= _h('transparency.total') ?> <i class="bi bi-arrow-down-up trans-sort-icon" aria-hidden="true"></i></button></th>
                <th class="trans-sortable" data-sort="accepted"><button type="button" class="th-sort"><?= _h('transparency.reviewed') ?> <i class="bi bi-arrow-down-up trans-sort-icon" aria-hidden="true"></i></button></th>
                <th class="trans-sortable" data-sort="blocked"><button type="button" class="th-sort"><?= _h('transparency.blocked') ?> <i class="bi bi-arrow-down-up trans-sort-icon" aria-hidden="true"></i></button></th>
                <th class="trans-sortable" data-sort="pending"><button type="button" class="th-sort"><?= _h('transparency.pending') ?> <i class="bi bi-arrow-down-up trans-sort-icon" aria-hidden="true"></i></button></th>
            </tr>
        </thead>
        <tbody id="trans-body"></tbody>
    </table>
    </div>
    <div class="trans-pagination" id="trans-pagination"></div>
</div>
