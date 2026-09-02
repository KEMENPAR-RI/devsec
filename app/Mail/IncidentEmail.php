<?php

namespace App\Mail;

/**
 * The incident/judol side of the mail system - a TemplatedMail with a name that
 * says what it carries, so the notification classes and their tests read clearly.
 * All the rendering lives in the parent; see App\Notifications\Concerns\SendsIncidentEmail
 * for the data it's given.
 */
class IncidentEmail extends TemplatedMail {}
