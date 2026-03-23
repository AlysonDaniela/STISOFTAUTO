<?php
// empleados/includes/buk/config.php
// ⚠️ Ideal: mover BUK_TOKEN a .env en producción

const BUK_API_BASE         = 'https://sti.buk.cl/api/v1/chile';
const BUK_EMP_CREATE_PATH  = '/employees.json';
const BUK_JOB_CREATE_PATH  = '/employees/%d/jobs';
const BUK_PLAN_CREATE_PATH = '/employees/%d/plans';

const BUK_TOKEN            = 'bAVH6fNSraVT17MBv1ECPrfW';

const COMPANY_ID_FOR_JOBS  = 1;
const DEFAULT_LOCATION_ID  = 407;

const FALLBACK_BOSS_RUT    = '15871627-5'; // jefe comodín
const LOG_DIR              = __DIR__ . '/../../logs_buk';
