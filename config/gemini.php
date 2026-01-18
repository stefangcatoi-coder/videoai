<?php
// /var/www/video-ai/config/gemini.php

/**
 * ATENTIE: Inlocuieste 'YOUR_API_KEY_HERE' cu cheia ta reala de la Google AI Studio.
 * Folosim gemini-1.5-flash ca fiind modelul stabil actual conform documentatiei.
 */
define('GEMINI_API_KEY', 'YOUR_API_KEY_HERE');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent');
