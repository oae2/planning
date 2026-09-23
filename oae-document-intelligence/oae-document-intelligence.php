<?php
/**
 * Plugin Name: OAE AI Agent Unified – Document Intelligence
 * Description: Verified PDF ingestion, Thai normalization, legal-aware chunking, AI PDF understanding and Seed Set integration for OAE AI Assistance.
 * Version: 2.3.1
 * Author: Office of Agricultural Economics
 * Requires at least: 6.2
 * Requires PHP: 8.0
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/includes/class-oaeodi-core-v230.php';
require_once __DIR__ . '/includes/class-oaeodi-ai-pdf.php';
OAEODI_AI_PDF::boot();
