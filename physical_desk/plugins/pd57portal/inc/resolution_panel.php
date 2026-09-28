<?php

require_once __DIR__ . '/resolution.php';

$userId = (int)Session::getLoginUserID();

$isRequester =
    pd57_resolution_is_requester(
        (int)$id,
        $userId
    );

$status =
    (int)($ticket->fields['status'] ?? 0);

$canResolve =
    !$isRequester &&
    $ticket->can(
        (int)$id,
        UPDATE
    );

$csrf =
    pd57_h(
        Session::getNewCSRFToken()
    );

$result =
    trim(
        (string)(
            $_GET['resolution']
            ?? ''
        )
    );

if ($result === 'proposed') {

    echo '<div class="pd57-resolution-flash">';
    echo '<strong>Resolution proposed.</strong> ';
    echo 'The employee will be asked to confirm whether the issue is actually solved.';
    echo '</div>';
}

if ($result === 'confirmed') {

    echo '<div class="pd57-resolution-flash">';
    echo '<strong>Request closed.</strong> ';
    echo 'The employee confirmed the resolution.';
    echo '</div>';
}

if ($result === 'reopened') {

    $target = pd57_h(pd57_p4_visible_designation_label((string)($_GET['escalated']??'Employee Services Desk')));

    echo '<div class="pd57-resolution-flash warning">';
    echo '<strong>Request reopened.</strong> ';
    echo 'The employee said the issue is still unresolved. ';
    echo 'It has been escalated to ' .
        $target .
        '.';
    echo '</div>';
}


echo '<section class="panel pd57-resolution-card">';

echo '<div class="pd57-resolution-heading">';

echo '<div>';

echo '<p class="eyebrow">RESOLUTION</p>';

if ($status === 5) {

    echo '<h2>Resolution proposed</h2>';

} elseif ($status === 6) {

    echo '<h2>Request resolved</h2>';

} else {

    echo '<h2>Close the loop</h2>';
}

echo '</div>';

echo '</div>';


if (
    $status === 5 &&
    $isRequester
) {

    echo '<p class="pd57-resolution-question">';
    echo 'Has this actually been resolved?';
    echo '</p>';

    echo '<p class="pd57-resolution-copy">';
    echo 'Your confirmation closes the request. ';
    echo 'If the issue is still unresolved, we will reopen it ';
    echo 'and escalate it to the next professional designation.';
    echo '</p>';

    echo '<div class="pd57-resolution-actions">';

    echo '<form method="post" action="/plugins/pd57portal/front/resolution.php" class="pd57-reject-form">';

    echo '<input type="hidden" name="id" value="' .
        (int)$id .
        '">';

    echo '<input type="hidden" name="pd57_resolution_action" value="confirm">';

    echo '<input type="hidden" name="_glpi_csrf_token" value="' .
        $csrf .
        '">';

    echo '<button type="submit" class="pd57-resolve-primary">';
    echo 'Yes, resolved';
    echo '</button>';

    echo '</form>';


    $csrfReject =
        pd57_h(
            Session::getNewCSRFToken()
        );

    echo '<form method="post" action="/plugins/pd57portal/front/resolution.php">';

    echo '<input type="hidden" name="id" value="' .
        (int)$id .
        '">';

    echo '<input type="hidden" name="pd57_resolution_action" value="reject">';

    echo '<input type="hidden" name="_glpi_csrf_token" value="' .
        $csrfReject .
        '">';

    echo '<label for="pd57-unresolved-note">What is still unresolved? <span class="optional">Helpful, not required</span></label>';

    echo '<textarea id="pd57-unresolved-note" name="unresolved_note" rows="3" maxlength="1500" placeholder="Tell the next professional owner what still needs attention."></textarea>';

    echo '<button type="submit" class="pd57-resolve-secondary">';
    echo 'No, escalate this request';
    echo '</button>';

    echo '</form>';

    echo '</div>';

} elseif (
    $status === 5 &&
    !$isRequester
) {

    echo '<p class="pd57-resolution-copy">';
    echo 'Waiting for employee confirmation. ';
    echo 'The request will only close after the employee confirms ';
    echo 'that the resolution worked.';
    echo '</p>';

} elseif ($status === 6) {

    echo '<p class="pd57-resolution-copy">';
    echo 'The resolution has been confirmed and this request is closed.';
    echo '</p>';

} elseif ($canResolve) {

    echo '<p class="pd57-resolution-copy">';
    echo 'When the work is complete, propose a resolution. ';
    echo 'The employee will be asked to confirm it before final closure.';
    echo '</p>';

    echo '<form method="post" action="/plugins/pd57portal/front/resolution.php" class="pd57-resolution-form">';

    echo '<input type="hidden" name="id" value="' .
        (int)$id .
        '">';

    echo '<input type="hidden" name="pd57_resolution_action" value="propose">';

    echo '<input type="hidden" name="_glpi_csrf_token" value="' .
        $csrf .
        '">';

    echo '<label for="pd57-resolution-note">';
    echo 'Resolution note';
    echo '</label>';

    echo '<textarea id="pd57-resolution-note" name="resolution_note" rows="3" maxlength="1500" placeholder="Briefly explain what was done to resolve the request."></textarea>';

    echo '<button type="submit" class="pd57-resolve-primary">';
    echo 'Resolve request';
    echo '</button>';

    echo '</form>';

} else {

    echo '<p class="pd57-resolution-copy">';
    echo 'The handling team is still working on this request.';
    echo '</p>';
}

echo '</section>';
