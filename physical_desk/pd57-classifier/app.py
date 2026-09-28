"""
PD57 Classifier Service
=======================
Local MiniLM-based zero-shot NLI classifier for Physical Desk ticket intake.

Uses cross-encoder/nli-MiniLM2-L6-H768 to classify employee request text
against the PD57 category taxonomy. Returns top-N scored suggestions with
confidence values. Never blocks ticket creation — failures always fall back
to "Manual Triage".

Architecture:
  - Single worker, single model instance (fits in ~300MB RAM)
  - No external API calls — fully offline after build
  - Stateless — every request is independent
  - Human-in-the-loop: suggestions are NEVER auto-applied
"""

import logging
import os
import time
from typing import Optional

import numpy as np
import torch
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
from transformers import AutoModelForSequenceClassification, AutoTokenizer

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

MODEL_NAME = os.getenv("PD57_MODEL", "cross-encoder/nli-MiniLM2-L6-H768")
CONFIDENCE_THRESHOLD = float(os.getenv("PD57_CONFIDENCE_THRESHOLD", "0.45"))
MAX_SUGGESTIONS = int(os.getenv("PD57_MAX_SUGGESTIONS", "3"))
LOG_LEVEL = os.getenv("PD57_LOG_LEVEL", "INFO")

logging.basicConfig(
    level=getattr(logging, LOG_LEVEL),
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger("pd57-classifier")

# ---------------------------------------------------------------------------
# PD57 Category Taxonomy — mirrors glpi_itilcategories
# ---------------------------------------------------------------------------
# Each entry uses a canonical category path, independent of database IDs.
# Hypotheses are natural-language statements for zero-shot classification.

CATEGORY_LABELS: list[dict] = [
    # --- HR ---
    {"hypothesis": "This is about requesting time off or leave from work.",
         "name": "Leave Request", "path": "HR > Leave & Attendance > Leave Request"},
    {"hypothesis": "This is about correcting attendance records or clock-in errors.",
         "name": "Attendance Correction", "path": "HR > Leave & Attendance > Attendance Correction"},
    {"hypothesis": "This is about work shifts, scheduling, or roster queries.",
         "name": "Shift / Schedule Query", "path": "HR > Leave & Attendance > Shift / Schedule Query"},
    {"hypothesis": "This is about employee personal records, documents, or files.",
         "name": "Employee Records", "path": "HR > Employee Records"},
    {"hypothesis": "This is about employee benefits, insurance, or perks.",
         "name": "Benefits", "path": "HR > Benefits"},
    {"hypothesis": "This is about hiring, recruitment, or onboarding new employees.",
         "name": "Hiring & Onboarding", "path": "HR > Hiring & Onboarding"},
    {"hypothesis": "This is about workplace conflicts, harassment, wellbeing, or people support.",
         "name": "Workplace / People Support", "path": "HR > Workplace / People Support"},
    {"hypothesis": "This is about HR policies, company handbook, or general HR questions.",
         "name": "Policy / HR Query", "path": "HR > Policy / HR Query"},
    {"hypothesis": "This is a general HR question not covered by other categories.",
         "name": "Other HR", "path": "HR > Other HR"},

    # --- IT ---
    {"hypothesis": "This is a general IT help desk or tech support request.",
         "name": "Service Desk / General Support", "path": "IT > Service Desk / General Support"},
    {"hypothesis": "This is about a laptop or desktop computer issue.",
         "name": "Laptop / Desktop", "path": "IT > Hardware > Laptop / Desktop"},
    {"hypothesis": "This is about a printer issue or printing problem.",
         "name": "Printer", "path": "IT > Hardware > Printer"},
    {"hypothesis": "This is about a computer peripheral like mouse, keyboard, monitor, or headset.",
         "name": "Peripheral", "path": "IT > Hardware > Peripheral"},
    {"hypothesis": "This is about a point-of-sale terminal or check-in kiosk device.",
         "name": "POS / Check-in Device", "path": "IT > Hardware > POS / Check-in Device"},
    {"hypothesis": "This is about Wi-Fi connectivity or wireless network issues.",
         "name": "Wi-Fi", "path": "IT > Network > Wi-Fi"},
    {"hypothesis": "This is about internet connectivity or browsing issues.",
         "name": "Internet", "path": "IT > Network > Internet"},
    {"hypothesis": "This is about wired network, LAN, or ethernet connectivity.",
         "name": "LAN / Connectivity", "path": "IT > Network > LAN / Connectivity"},
    {"hypothesis": "This is about network equipment like routers, switches, or access points.",
         "name": "Network Equipment", "path": "IT > Network > Network Equipment"},
    {"hypothesis": "This is about password reset, login issues, or forgotten credentials.",
         "name": "Password / Login", "path": "IT > Identity & Access > Password / Login"},
    {"hypothesis": "This is about requesting access or permissions to systems or folders.",
         "name": "Permission / Access", "path": "IT > Identity & Access > Permission / Access"},
    {"hypothesis": "This is about creating a new user account or system access for a new employee.",
         "name": "New Account", "path": "IT > Identity & Access > New Account"},
    {"hypothesis": "This is about an account that is locked out or disabled.",
         "name": "Account Lockout", "path": "IT > Identity & Access > Account Lockout"},
    {"hypothesis": "This is about a suspicious email, phishing attempt, or email scam.",
         "name": "Suspicious Email / Phishing", "path": "IT > Cybersecurity > Suspicious Email / Phishing"},
    {"hypothesis": "This is about account security, unauthorized access, or compromised credentials.",
         "name": "Account Security", "path": "IT > Cybersecurity > Account Security"},
    {"hypothesis": "This is about device security, malware, virus, or endpoint protection.",
         "name": "Device Security", "path": "IT > Cybersecurity > Device Security"},
    {"hypothesis": "This is about a cybersecurity incident or data breach.",
         "name": "Security Incident", "path": "IT > Cybersecurity > Security Incident"},
    {"hypothesis": "This is about a software application, app installation, or software issue.",
         "name": "Applications / Software", "path": "IT > Applications / Software"},
    {"hypothesis": "This is about point-of-sale systems or member check-in software.",
         "name": "POS / Check-in Systems", "path": "IT > POS / Check-in Systems"},
    {"hypothesis": "This is about audio or visual equipment in a studio, like speakers or screens.",
         "name": "Studio Audio / Visual", "path": "IT > Studio Audio / Visual"},
    {"hypothesis": "This is about CCTV cameras, surveillance, or security camera systems.",
         "name": "CCTV / Security Systems", "path": "IT > CCTV / Security Systems"},
    {"hypothesis": "This is a general IT question not covered by other categories.",
         "name": "Other IT", "path": "IT > Other IT"},

    # --- Payroll ---
    {"hypothesis": "This is about salary, pay rate, or compensation questions.",
         "name": "Salary", "path": "Payroll > Salary"},
    {"hypothesis": "This is about expense reimbursement or travel expense claims.",
         "name": "Reimbursement", "path": "Payroll > Reimbursement"},
    {"hypothesis": "This is about viewing or accessing a payslip or pay statement.",
         "name": "Payslip", "path": "Payroll > Payslip"},
    {"hypothesis": "This is about payroll deductions, garnishments, or withholdings.",
         "name": "Deduction", "path": "Payroll > Deduction"},
    {"hypothesis": "This is about changing bank account or payment details for salary.",
         "name": "Bank / Payment Details", "path": "Payroll > Bank / Payment Details"},
    {"hypothesis": "This is about tax forms, tax withholding, or payroll documentation.",
         "name": "Tax / Payroll Documentation", "path": "Payroll > Tax / Payroll Documentation"},
    {"hypothesis": "This is a general payroll question not covered by other categories.",
         "name": "Other Payroll", "path": "Payroll > Other Payroll"},

    # --- Operations ---
    {"hypothesis": "This is about barre exercise equipment in the fitness studio.",
         "name": "Barre Equipment", "path": "Operations > Studio Equipment > Barre Equipment"},
    {"hypothesis": "This is about weights, dumbbells, or free weight equipment.",
         "name": "Weights / Dumbbells", "path": "Operations > Studio Equipment > Weights / Dumbbells"},
    {"hypothesis": "This is about resistance bands, cables, or resistance training equipment.",
         "name": "Resistance Equipment", "path": "Operations > Studio Equipment > Resistance Equipment"},
    {"hypothesis": "This is about exercise mats, yoga props, or group class equipment.",
         "name": "Exercise / Class Equipment", "path": "Operations > Studio Equipment > Exercise / Class Equipment"},
    {"hypothesis": "This is about treadmills, bikes, rowing machines, or cardio equipment.",
         "name": "Cardio Equipment", "path": "Operations > Studio Equipment > Cardio Equipment"},
    {"hypothesis": "This is about other fitness equipment not specifically categorised.",
         "name": "Other Fitness Equipment", "path": "Operations > Studio Equipment > Other Fitness Equipment"},
    {"hypothesis": "This is about heating, ventilation, air conditioning, or temperature issues.",
         "name": "HVAC / AC", "path": "Operations > Facility Maintenance > HVAC / AC"},
    {"hypothesis": "This is about electrical issues, power outage, or lighting problems.",
         "name": "Electrical", "path": "Operations > Facility Maintenance > Electrical"},
    {"hypothesis": "This is about plumbing, water leak, or bathroom facilities issues.",
         "name": "Plumbing / Water", "path": "Operations > Facility Maintenance > Plumbing / Water"},
    {"hypothesis": "This is about door locks, key cards, access control, or building entry.",
         "name": "Access Control", "path": "Operations > Facility Maintenance > Access Control"},
    {"hypothesis": "This is about general building maintenance, repairs, or facility issues.",
         "name": "General Facility", "path": "Operations > Facility Maintenance > General Facility"},
    {"hypothesis": "This is about cleaning, housekeeping, or hygiene in the facility.",
         "name": "Housekeeping", "path": "Operations > Housekeeping"},
    {"hypothesis": "This is about ordering supplies, inventory management, or stock replenishment.",
         "name": "Supplies / Inventory", "path": "Operations > Supplies / Inventory"},
    {"hypothesis": "This is about a vendor, supplier, or third-party service issue.",
         "name": "Vendor Issue", "path": "Operations > Vendor Issue"},
    {"hypothesis": "This is about day-to-day studio operations, class scheduling, or studio logistics.",
         "name": "Studio Operations", "path": "Operations > Studio Operations"},
    {"hypothesis": "This is about a safety incident, injury, accident, or operational emergency.",
         "name": "Safety / Operational Incident", "path": "Operations > Safety / Operational Incident"},
    {"hypothesis": "This is a general operations question not covered by other categories.",
         "name": "Other Operations", "path": "Operations > Other Operations"},

    # --- Other ---
    {"hypothesis": "This is a general request or question that does not fit any specific department.",
         "name": "General Request", "path": "Other > General Request"},
]

# The fallback category when confidence is too low or classifier fails
MANUAL_TRIAGE_INFO = {
    "name": "Manual Triage",
    "path": "Other > Manual Triage",
    "confidence": 0.0,
    "source": "fallback",
}

# Phase 4 hierarchy. The deterministic router is intentionally narrow: it is
# only used when the request itself is unambiguous.  All other requests retain
# MiniLM/NLI assistance and can abstain to Employee Services Desk.
FAST_ROUTES = [
    (('treadmill', 'equipment is unsafe', 'studio equipment'), ('Studio Operations', 'Facilities & Equipment', 'Equipment Issue', 'Operations > Studio Equipment > Cardio Equipment')),
    (('salary has not arrived', 'not received my salary', 'unpaid wages'), ('Payroll', 'Salary Processing', 'Salary Not Received', 'Payroll > Salary')),
    (('medical reimbursement', 'reimbursement'), ('Payroll', 'Reimbursements', 'Reimbursement', 'Payroll > Reimbursement')),
    (('payslip', 'pay slip'), ('Payroll', 'Payslips & Payroll Documents', 'Payslip', 'Payroll > Payslip')),
    (('account is locked', 'account locked', 'locked out'), ('IT', 'Identity & Access', 'Account Lockout', 'IT > Identity & Access > Account Lockout')),
    (('wi-fi', 'wifi'), ('IT', 'Network & Connectivity', 'Wi-Fi', 'IT > Network > Wi-Fi')),
    (('experience letter',), ('HR', 'HR Documentation', 'Experience Letter', 'HR > Employee Records')),
    (('laptop will not start', 'laptop won\'t start', 'desktop will not start'), ('IT', 'Desktop & Device Support', 'Device Support', 'IT > Hardware > Laptop / Desktop')),
]

HIERARCHY = {
    "IT": {"hypothesis": "The employee needs technology, device, account, network, application, or infrastructure support.", "teams": {
        "Desktop & Device Support": {"hypothesis":"A laptop, desktop, monitor, or workplace device is broken or will not start.", "types":{"Device Support":"A work computer or device needs repair or support."}},
        "Identity & Access": {"hypothesis":"A company account, sign-in, access permission, or identity is unavailable.", "types":{"Account Lockout":"A company account is locked, disabled, blocked, or inaccessible."}},
        "Network & Connectivity": {"hypothesis":"Wi-Fi, internet, or network connectivity is unavailable.", "types":{"Wi-Fi":"Wireless network or Wi-Fi connectivity is unavailable."}},
    }},
    "Payroll": {"hypothesis": "The employee needs salary, reimbursement, payslip, tax, deduction, or final settlement help.", "teams": {
        "Salary Processing":{"hypothesis":"Salary or wages are missing, delayed, or need payroll processing.","types":{"Salary Not Received":"Employee salary or wages have not been paid or are delayed."}},
        "Reimbursements":{"hypothesis":"An employee expense or medical reimbursement needs review or payment.","types":{"Reimbursement":"A submitted reimbursement needs a status or payment review."}},
        "Payslips & Payroll Documents":{"hypothesis":"The employee needs a payslip or payroll document.","types":{"Payslip":"A payslip or pay statement is requested."}},
    }},
    "HR": {"hypothesis":"The employee needs HR documentation, leave, attendance, benefits, recruitment, or employee-relations support.", "teams": {
        "HR Documentation":{"hypothesis":"The employee needs an experience letter, employment letter, or HR document.","types":{"Experience Letter":"An experience letter or employment document is requested."}},
    }},
    "Studio Operations": {"hypothesis":"The request concerns studio facilities, equipment, scheduling, front desk, supplies, vendors, or maintenance.", "teams": {
        "Facilities & Equipment":{"hypothesis":"Studio equipment, a treadmill, facilities, or safety needs attention.","types":{"Equipment Issue":"Studio equipment or facilities are broken or unsafe."}},
    }},
}

LEGACY_PATHS = {"Device Support":"IT > Hardware > Laptop / Desktop","Account Lockout":"IT > Identity & Access > Account Lockout","Wi-Fi":"IT > Network > Wi-Fi","Salary Not Received":"Payroll > Salary","Reimbursement":"Payroll > Reimbursement","Payslip":"Payroll > Payslip","Experience Letter":"HR > Employee Records","Equipment Issue":"Operations > Studio Equipment > Cardio Equipment"}

def _fast_route(text: str):
    value = text.lower()
    for signals, route in FAST_ROUTES:
        if any(signal in value for signal in signals):
            return route
    return None

def _nli_best(text: str, choices: dict):
    if not _model_ready or _model is None or _tokenizer is None: return None, 0.0
    names=list(choices.keys()); pairs=[(text, choices[n]["hypothesis"]) for n in names]
    inputs=_tokenizer(pairs,padding=True,truncation=True,max_length=256,return_tensors="pt")
    with torch.no_grad(): logits=_model(**inputs).logits
    scores=torch.softmax(torch.stack([logits[:,0],logits[:,1]],dim=1),dim=1)[:,1].numpy()
    index=int(scores.argmax()); return names[index], float(scores[index])

def _hierarchical_route(text: str, threshold: float):
    department, score=_nli_best(text,HIERARCHY)
    if not department or score < threshold: return None
    team, team_score=_nli_best(text,HIERARCHY[department]["teams"])
    if not team or team_score < threshold: return None
    request_type, type_score=_nli_best(text,HIERARCHY[department]["teams"][team]["types"])
    if not request_type or type_score < threshold: return None
    return department, team, request_type, min(score,team_score,type_score)


# ---------------------------------------------------------------------------
# Pydantic Models
# ---------------------------------------------------------------------------

class ClassifyRequest(BaseModel):
    """Incoming classification request from GLPI."""
    text: str = Field(..., min_length=3, max_length=5000,
                      description="The employee's request text (title + description).")
    top_n: int = Field(default=MAX_SUGGESTIONS, ge=1, le=10,
                       description="Number of suggestions to return.")
    threshold: Optional[float] = Field(default=None, ge=0.0, le=1.0,
                                        description="Override confidence threshold.")


class CategorySuggestion(BaseModel):
    """A single category suggestion with confidence score."""
    name: str
    path: str
    confidence: float = Field(..., ge=0.0, le=1.0)
    source: str = "classifier"


class ClassifyResponse(BaseModel):
    """Classification response returned to GLPI."""
    model_config = {"protected_namespaces": ()}
    suggestions: list[CategorySuggestion]
    needs_human_review: bool = True  # ALWAYS true — human-in-the-loop
    fallback_used: bool = False
    model_name: str = MODEL_NAME
    inference_time_ms: float = 0.0
    department: str = "Other / unknown"
    team: str = "Employee Services Desk"
    request_type: str = "Manual Triage"
    hierarchy_confidence: float = 0.0


class HealthResponse(BaseModel):
    """Health check response."""
    model_config = {"protected_namespaces": ()}
    status: str
    model_loaded: bool
    model_name: str
    category_count: int
    version: str = "1.0.0"


# ---------------------------------------------------------------------------
# FastAPI App
# ---------------------------------------------------------------------------

app = FastAPI(
    title="PD57 Classifier",
    description="Local MiniLM request classifier for Physical Desk / PD57",
    version="1.0.0",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["POST", "GET"],
    allow_headers=["*"],
)

# Global cold-start timing metrics
_process_start_time = time.time()
_model_load_start_time = 0.0
_model_load_end_time = 0.0
_model_load_time = 0.0
_model = None
_tokenizer = None
_model_ready = False


def _load_model():
    """Load the cross-encoder model. Called once at startup."""
    global _model, _tokenizer, _model_ready, _model_load_start_time, _model_load_end_time, _model_load_time
    try:
        logger.info("Loading model: %s (HF_HUB_OFFLINE=%s)", MODEL_NAME, os.getenv("HF_HUB_OFFLINE", "0"))
        _model_load_start_time = time.time()
        try:
            _tokenizer = AutoTokenizer.from_pretrained(MODEL_NAME, local_files_only=True)
            _model = AutoModelForSequenceClassification.from_pretrained(MODEL_NAME, local_files_only=True)
        except Exception:
            logger.warning("local_files_only failed, trying default load...")
            _tokenizer = AutoTokenizer.from_pretrained(MODEL_NAME)
            _model = AutoModelForSequenceClassification.from_pretrained(MODEL_NAME)
        _model.eval()
        _model_load_end_time = time.time()
        _model_load_time = _model_load_end_time - _model_load_start_time
        _model_ready = True
        logger.info("Model loaded in %.2fs (total readiness from process start: %.2fs)",
                    _model_load_time, _model_load_end_time - _process_start_time)
    except Exception:
        logger.exception("FATAL: Failed to load model")
        _model_ready = False


@app.on_event("startup")
async def startup_event():
    _load_model()


@app.get("/health", response_model=HealthResponse)
async def health():
    """Health check endpoint."""
    return HealthResponse(
        status="ok" if _model_ready else "degraded",
        model_loaded=_model_ready,
        model_name=MODEL_NAME,
        category_count=len(CATEGORY_LABELS),
    )


@app.get("/health/ready")
async def health_ready():
    """Readiness probe endpoint. Returns 200 when model is loaded, 530/503 otherwise."""
    if _model_ready and _model is not None:
        readiness_duration = round(_model_load_end_time - _process_start_time, 2)
        return {
            "status": "ready",
            "model_name": MODEL_NAME,
            "readiness_time_s": readiness_duration,
            "model_load_time_s": round(_model_load_time, 2),
            "offline_mode": os.getenv("HF_HUB_OFFLINE") == "1" or os.getenv("TRANSFORMERS_OFFLINE") == "1",
        }
    raise HTTPException(status_code=503, detail="Model loading in progress or failed")

def _classify(text: str, top_n: int, threshold: float) -> list[CategorySuggestion]:
    """
    Run zero-shot NLI classification across PD57 categories.
    Uses binary entailment probability vs contradiction for each category hypothesis.
    """
    if not _model_ready or _model is None or _tokenizer is None:
        return []

    hypotheses = [category["hypothesis"] for category in CATEGORY_LABELS]

    # Build premise-hypothesis pairs
    pairs = [(text, h) for h in hypotheses]

    inputs = _tokenizer(
        pairs,
        padding=True,
        truncation=True,
        max_length=256,
        return_tensors="pt",
    )

    with torch.no_grad():
        logits = _model(**inputs).logits  # shape: (N, 3)

    # NLI labels for MiniLM: 0=contradiction, 1=entailment, 2=neutral
    # Compute binary entailment score vs contradiction: P(entailment) / (P(entailment) + P(contradiction))
    con_logits = logits[:, 0]
    ent_logits = logits[:, 1]
    binary_probs = torch.softmax(torch.stack([con_logits, ent_logits], dim=1), dim=1)[:, 1].numpy()

    scored = []
    for idx, category in enumerate(CATEGORY_LABELS):
        score = float(binary_probs[idx])
        if score >= threshold:
            scored.append(CategorySuggestion(
                name=category["name"],
                path=category["path"],
                confidence=round(score, 4),
                source="classifier",
            ))

    # Sort by confidence descending, take top_n
    scored.sort(key=lambda s: s.confidence, reverse=True)
    return scored[:top_n]


# ---------------------------------------------------------------------------
# Endpoints
# ---------------------------------------------------------------------------

@app.post("/v1/classify", response_model=ClassifyResponse)
async def classify(req: ClassifyRequest):
    """
    Classify employee request text and return category suggestions.

    Always returns needs_human_review=True — the classifier is an assistant,
    not the final authority. If the classifier fails or returns no results
    above threshold, the Manual Triage fallback is used.
    """
    threshold = req.threshold if req.threshold is not None else CONFIDENCE_THRESHOLD
    start = time.time()

    try:
        route = _fast_route(req.text)
        hierarchical = None if route else _hierarchical_route(req.text, threshold)
        suggestions = _classify(req.text, req.top_n, threshold) if not (route or hierarchical) else []
        elapsed_ms = (time.time() - start) * 1000

        if route:
            department, team, request_type, path = route
            suggestions = [CategorySuggestion(name=request_type, path=path, confidence=0.99, source="fast_domain_router")]
        elif hierarchical:
            department, team, request_type, confidence = hierarchical
            suggestions = [CategorySuggestion(name=request_type, path=LEGACY_PATHS[request_type], confidence=round(confidence,4), source="hierarchical_minilm_nli")]

        if not suggestions:
            # Below threshold or model failure → Manual Triage fallback
            logger.info("Classification fallback: threshold=%.2f latency_ms=%.1f", threshold, elapsed_ms)
            return ClassifyResponse(
                suggestions=[CategorySuggestion(**MANUAL_TRIAGE_INFO)],
                needs_human_review=True,
                fallback_used=True,
                inference_time_ms=round(elapsed_ms, 1),
                department="Other / unknown", team="Employee Services Desk", request_type="Manual Triage", hierarchy_confidence=0.0,
            )

        logger.info("Classified: latency_ms=%.1f top_path=%s confidence=%.3f",
                    elapsed_ms, suggestions[0].path, suggestions[0].confidence)

        department, team, request_type = (route[:3] if route else (hierarchical[:3] if hierarchical else ("Other / unknown", "Employee Services Desk", "Manual Triage")))
        return ClassifyResponse(
            suggestions=suggestions,
            needs_human_review=True,
            fallback_used=False,
            inference_time_ms=round(elapsed_ms, 1),
            department=department, team=team, request_type=request_type,
            hierarchy_confidence=0.99 if route else float(hierarchical[3] if hierarchical else suggestions[0].confidence),
        )

    except Exception:
        elapsed_ms = (time.time() - start) * 1000
        logger.exception("Classification failed — returning Manual Triage fallback")
        return ClassifyResponse(
            suggestions=[CategorySuggestion(**MANUAL_TRIAGE_INFO)],
            needs_human_review=True,
            fallback_used=True,
            inference_time_ms=round(elapsed_ms, 1),
            department="Other / unknown", team="Employee Services Desk", request_type="Manual Triage", hierarchy_confidence=0.0,
        )


@app.get("/v1/categories")
async def list_categories():
    """Return the full category taxonomy the classifier knows about."""
    return {
        "categories": [
            {"name": info["name"], "path": info["path"]}
            for info in CATEGORY_LABELS
        ],
        "fallback": {
                    "name": "Manual Triage",
            "path": "Other > Manual Triage",
        },
        "total": len(CATEGORY_LABELS),
    }
