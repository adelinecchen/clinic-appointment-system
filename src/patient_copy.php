<?php

declare(strict_types=1);

function patient_language(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $requested = (string) ($_GET['lang'] ?? '');
    if ($requested === 'en' || $requested === 'es') {
        $_SESSION['patient_lang'] = $requested;
    }

    return ($_SESSION['patient_lang'] ?? 'en') === 'es' ? 'es' : 'en';
}

function patient_text(string $key): string
{
    $copy = [
        'en' => [
            'book_title' => 'Book your visit',
            'book_lede' => 'Choose a time that works for you. We will send a text reminder. Tap the link in the text to confirm.',
            'perk_password' => 'No password needed',
            'perk_change' => 'Change or cancel any time',
            'perk_text' => 'Reminders by text message',
            'card_title' => 'Make an appointment',
            'step_visit' => 'What kind of visit?',
            'wellness' => 'Wellness exam',
            'wellness_note' => 'A regular check-up',
            'follow' => 'Follow-up visit',
            'follow_note' => 'After your last visit',
            'step_time' => 'Choose a date and time',
            'no_dates' => 'There are no open days right now. Please check again later.',
            'step_contact' => 'Your name and phone number',
            'full_name' => 'Full name',
            'phone' => 'Phone number',
            'person' => 'Name',
            'you' => 'You',
            'add_person' => 'Add another person',
            'remove_person' => 'Remove',
            'party_hint' => 'Each person gets the next 15 minutes.',
            'err_visit' => 'Choose a kind of visit.',
            'err_date' => 'Choose a date.',
            'err_time' => 'Choose an open time.',
            'err_name' => 'Enter your name.',
            'err_phone' => 'Enter a 10-digit phone number.',
            'err_party_name' => 'Enter a name for each person, or remove the blank line.',
            'book_button' => 'Book my visit',
            'save_title' => 'We save your time',
            'save_note' => 'You will see your visit right away.',
            'text_title' => 'We send you a text',
            'text_note' => 'A reminder comes before your visit.',
            'confirm_title' => 'You tap Confirm',
            'confirm_note' => 'That tells the clinic you are coming.',
            'check' => 'Check status',
            'staff' => 'Staff login',
            'english' => 'English',
            'spanish' => 'Español',
            'full' => 'Full',
            'thanks' => 'Your visit is booked',
            'text_soon' => 'We will text',
            'text_soon_end' => 'Tap Confirm in that message when you are ready.',
            'with' => 'Also coming',
            'change' => 'Change this visit',
            'book_another' => 'Book another visit',
            'none' => 'Book a visit to see it here.',
            'status_title' => 'Check your visit',
            'status_lede' => 'Enter the phone number you used to book.',
            'find' => 'Find my visit',
            'missing' => 'We don’t see a visit for that number.',
            'book_instead' => 'Book a visit',
            'cancel' => 'Cancel visit',
            'canceled' => 'This visit is canceled.',
            'canceled_note' => 'You can book a new time whenever you are ready.',
            'reschedule' => 'Pick a new time',
            'save_change' => 'Save this time',
            'coming' => 'You are coming',
        ],
        'es' => [
            'book_title' => 'Reserve su visita',
            'book_lede' => 'Elija un horario. Le enviaremos un mensaje de texto. Toque el enlace para confirmar.',
            'perk_password' => 'No necesita contraseña',
            'perk_change' => 'Cambie o cancele cuando quiera',
            'perk_text' => 'Recordatorios por mensaje de texto',
            'card_title' => 'Hacer una cita',
            'step_visit' => '¿Qué tipo de visita?',
            'wellness' => 'Examen de bienestar',
            'wellness_note' => 'Una revisión regular',
            'follow' => 'Visita de seguimiento',
            'follow_note' => 'Después de su última visita',
            'step_time' => 'Elija una fecha y una hora',
            'no_dates' => 'No hay días abiertos en este momento. Vuelva a revisar más tarde.',
            'step_contact' => 'Su nombre y teléfono',
            'full_name' => 'Nombre completo',
            'phone' => 'Teléfono',
            'person' => 'Nombre',
            'you' => 'Usted',
            'add_person' => 'Agregar otra persona',
            'remove_person' => 'Quitar',
            'party_hint' => 'Cada persona necesita los siguientes 15 minutos.',
            'err_visit' => 'Elija un tipo de visita.',
            'err_date' => 'Elija una fecha.',
            'err_time' => 'Elija un horario libre.',
            'err_name' => 'Escriba su nombre.',
            'err_phone' => 'Escriba un teléfono de 10 dígitos.',
            'err_party_name' => 'Escriba el nombre de cada persona, o quite la línea vacía.',
            'book_button' => 'Reservar mi visita',
            'save_title' => 'Guardamos su horario',
            'save_note' => 'Verá su visita de inmediato.',
            'text_title' => 'Le enviamos un mensaje',
            'text_note' => 'Un recordatorio llega antes de su visita.',
            'confirm_title' => 'Usted toca Confirmar',
            'confirm_note' => 'Así la clínica sabe que usted viene.',
            'check' => 'Ver mi cita',
            'staff' => 'Personal',
            'english' => 'English',
            'spanish' => 'Español',
            'full' => 'Lleno',
            'thanks' => 'Su visita está reservada',
            'text_soon' => 'Le enviaremos un mensaje a',
            'text_soon_end' => 'Toque Confirmar cuando llegue.',
            'with' => 'También viene',
            'change' => 'Cambiar esta visita',
            'book_another' => 'Reservar otra visita',
            'none' => 'Reserve una visita para verla aquí.',
            'status_title' => 'Ver su visita',
            'status_lede' => 'Escriba el teléfono que usó para reservar.',
            'find' => 'Buscar mi visita',
            'missing' => 'No encontramos una visita con ese número.',
            'book_instead' => 'Reservar una visita',
            'cancel' => 'Cancelar visita',
            'canceled' => 'Esta visita está cancelada.',
            'canceled_note' => 'Puede reservar otro horario cuando quiera.',
            'reschedule' => 'Elegir otro horario',
            'save_change' => 'Guardar este horario',
            'coming' => 'Usted viene',
        ],
    ];

    $lang = patient_language();

    return $copy[$lang][$key] ?? $copy['en'][$key] ?? $key;
}

function patient_fit_message(int $count): string
{
    if (patient_language() === 'es') {
        return $count === 1
            ? 'Este horario es para 1 persona. Reserve a los demás en otra visita.'
            : 'Este horario es para ' . $count . ' personas. Reserve a los demás en otra visita.';
    }

    return $count === 1
        ? 'This time fits 1 person. Book anyone else as another visit.'
        : 'This time fits ' . $count . ' people. Book anyone else as another visit.';
}

function patient_error_text(string $key, string $code): string
{
    if ($key === 'party') {
        return patient_fit_message((int) $code);
    }

    return patient_text('err_' . $code);
}
