#!/usr/bin/env python3
"""
LaraOrcest - Silent Graphical Explainer Video Generator
Generates a crisp, silent 720p 30fps H.264 MP4 explainer video (zero audio track)
showcasing LaraOrcest's multi-agent architecture, state engine, and CLI workflows.
"""

import os
import sys
import math
import time
import subprocess
from PIL import Image, ImageDraw, ImageFont

# Video Configuration
WIDTH = 1280
HEIGHT = 720
FPS = 30
SCENE_DURATION_SEC = 4
NUM_SCENES = 6
TOTAL_FRAMES = FPS * SCENE_DURATION_SEC * NUM_SCENES  # 720 frames = 24 seconds
OUTPUT_DIR = "docs/public/videos"
OUTPUT_FILE = os.path.join(OUTPUT_DIR, "lara-orcest-overview.mp4")

# Color Palette (Dark Theme / Filament + Next.js aesthetic)
COLOR_BG = (9, 13, 22)
COLOR_GRID = (22, 32, 50)
COLOR_CARD_BG = (16, 24, 40)
COLOR_CARD_BORDER = (38, 52, 78)
COLOR_CARD_ELV = (22, 32, 54)
COLOR_CARD_ELV_BORDER = (48, 66, 98)

COLOR_BRAND_RED = (255, 45, 32)
COLOR_BRAND_RED_SOFT = (255, 45, 32, 40)
COLOR_INDIGO = (99, 102, 241)
COLOR_CYAN = (6, 182, 212)
COLOR_EMERALD = (16, 185, 129)
COLOR_AMBER = (245, 158, 11)

COLOR_TEXT_1 = (248, 250, 252)
COLOR_TEXT_2 = (148, 163, 184)
COLOR_TEXT_3 = (100, 116, 139)

# Font Paths
FONT_BOLD_PATH = "/usr/share/fonts/julietaula-montserrat-fonts/Montserrat-Bold.otf"
FONT_REG_PATH = "/usr/share/fonts/julietaula-montserrat-fonts/Montserrat-Regular.otf"
FONT_MONO_PATH = "/usr/share/fonts/adobe-source-code-pro-fonts/SourceCodePro-Bold.otf"

# Load Fonts
font_title = ImageFont.truetype(FONT_BOLD_PATH, 34)
font_h1 = ImageFont.truetype(FONT_BOLD_PATH, 26)
font_h2 = ImageFont.truetype(FONT_BOLD_PATH, 20)
font_h3 = ImageFont.truetype(FONT_BOLD_PATH, 16)
font_body = ImageFont.truetype(FONT_REG_PATH, 15)
font_body_sm = ImageFont.truetype(FONT_REG_PATH, 13)
font_badge = ImageFont.truetype(FONT_BOLD_PATH, 12)
font_mono = ImageFont.truetype(FONT_MONO_PATH, 14)
font_mono_sm = ImageFont.truetype(FONT_MONO_PATH, 12)
font_mono_lg = ImageFont.truetype(FONT_MONO_PATH, 16)

# Load Logo
logo_img = None
if os.path.exists("docs/public/logo.png"):
    try:
        raw_logo = Image.open("docs/public/logo.png").convert("RGBA")
        logo_img = raw_logo
    except Exception as e:
        print(f"Warning: could not open logo: {e}")

def create_base_canvas():
    """Create background with subtle tech grid texture."""
    im = Image.new("RGB", (WIDTH, HEIGHT), COLOR_BG)
    d = ImageDraw.Draw(im)
    # Subtle grid lines
    grid_spacing = 40
    for x in range(0, WIDTH, grid_spacing):
        d.line([(x, 0), (x, HEIGHT)], fill=COLOR_GRID, width=1)
    for y in range(0, HEIGHT, grid_spacing):
        d.line([(0, y), (WIDTH, y)], fill=COLOR_GRID, width=1)
    return im

BASE_CANVAS = create_base_canvas()

def draw_header(d, tag_text, title_text, subtitle_text, scene_idx):
    """Draw common top header for informational scenes."""
    # Top scene counter & tag badge
    d.rectangle([50, 36, 175, 60], fill=(255, 45, 32, 30), outline=(255, 45, 32, 90), width=1)
    d.text((58, 41), f"SCENE 0{scene_idx + 1} • {tag_text}", font=font_badge, fill=COLOR_BRAND_RED)
    
    # Title & Subtitle
    d.text((50, 70), title_text, font=font_h1, fill=COLOR_TEXT_1)
    d.text((50, 105), subtitle_text, font=font_body, fill=COLOR_TEXT_2)
    
    # Top-right brand watermark
    d.text((WIDTH - 180, 42), "LaraOrcest", font=font_h2, fill=(255, 45, 32))
    d.text((WIDTH - 75, 46), "v1.0", font=font_badge, fill=COLOR_TEXT_3)

def draw_bottom_bar(d, frame_idx):
    """Draw bottom timeline and silent notice bar."""
    y = HEIGHT - 38
    d.line([(0, y), (WIDTH, y)], fill=(30, 42, 65), width=1)
    
    # Progress line
    prog = frame_idx / TOTAL_FRAMES
    prog_x = int(WIDTH * prog)
    d.line([(0, y), (prog_x, y)], fill=COLOR_BRAND_RED, width=2)
    
    # Silent notice & metadata
    d.text((50, y + 10), "SILENT GRAPHICAL OVERVIEW  •  ZERO AUDIO STREAM", font=font_badge, fill=COLOR_TEXT_3)
    d.text((WIDTH - 280, y + 10), "100% NATIVE PHP  •  LARAVEL MULTI-AGENT", font=font_badge, fill=COLOR_TEXT_3)

def draw_rounded_card(d, bbox, fill=COLOR_CARD_BG, outline=COLOR_CARD_BORDER, radius=12):
    """Draw rounded rectangle with custom border."""
    d.rounded_rectangle(bbox, radius=radius, fill=fill, outline=outline, width=1)

def draw_badge(d, x, y, text, color=COLOR_BRAND_RED, bg=(255, 45, 32, 25)):
    """Draw a micro-badge pill."""
    bbox = font_badge.getbbox(text)
    w = bbox[2] - bbox[0] + 16
    h = 22
    d.rounded_rectangle([x, y, x + w, y + h], radius=11, fill=bg, outline=color, width=1)
    d.text((x + 8, y + 3), text, font=font_badge, fill=color)
    return w

def render_scene_1(frame_in_scene):
    """Scene 1: Brand & Hero Identity (0s - 4s)."""
    im = BASE_CANVAS.copy()
    d = ImageDraw.Draw(im)
    t = frame_in_scene / 120.0
    
    # Pulsing Radial Halo Glow behind Logo
    cx, cy = 640, 220
    glow_r = int(120 + 20 * math.sin(t * math.pi * 2))
    d.ellipse([cx - glow_r, cy - glow_r, cx + glow_r, cy + glow_r], fill=(255, 45, 32, 15), outline=(255, 45, 32, 35))
    
    # Logo
    if logo_img:
        logo_size = 140
        logo_resized = logo_img.resize((logo_size, logo_size), Image.Resampling.LANCZOS)
        # Handle alpha compositing
        im.paste(logo_resized, (cx - logo_size//2, cy - logo_size//2), logo_resized)
    
    # Brand Title
    title = "LaraOrcest"
    bbox_t = font_title.getbbox(title)
    w_t = bbox_t[2] - bbox_t[0]
    d.text((cx - w_t//2, 320), title, font=font_title, fill=COLOR_TEXT_1)
    
    # Red accent dot on title
    d.ellipse([cx + w_t//2 + 4, 342, cx + w_t//2 + 14, 352], fill=COLOR_BRAND_RED)
    
    # Subtitle
    sub = "Autonomous Multi-Agent Pods for Modern Laravel"
    bbox_s = font_h2.getbbox(sub)
    w_s = bbox_s[2] - bbox_s[0]
    d.text((cx - w_s//2, 375), sub, font=font_h2, fill=COLOR_CYAN)
    
    # Tagline
    tagline = "CrewAI-Style Role Specialization + LangGraph-Style Directed Graphs"
    bbox_tag = font_body.getbbox(tagline)
    w_tag = bbox_tag[2] - bbox_tag[0]
    d.text((cx - w_tag//2, 415), tagline, font=font_body, fill=COLOR_TEXT_2)
    
    # 3 Hero Feature Cards (Staggered fade/slide in)
    cards = [
        ("⚡ 100% Native PHP 8.2+", "Runs via Composer with zero Python runtime required inside Laravel.", COLOR_BRAND_RED),
        ("🌐 Direct OpenAI / LiteLLM Proxy", "Seamless HTTP driver for LiteLLM, Ollama, vLLM, OpenRouter & Claude.", COLOR_CYAN),
        ("🛡️ Sandboxed Tools & Safe Git", "PathGuard isolation, Artisan whitelist, and Human-in-the-Loop review gates.", COLOR_EMERALD)
    ]
    
    card_w = 360
    card_h = 100
    spacing = 30
    total_w = len(cards) * card_w + (len(cards) - 1) * spacing
    start_x = (WIDTH - total_w) // 2
    
    for i, (ctitle, cdesc, ccolor) in enumerate(cards):
        # Staggered animation
        slide_offset = max(0, int((1.0 - min(1.0, (t * 2.5 - i * 0.2))) * 25))
        card_x = start_x + i * (card_w + spacing)
        card_y = 475 + slide_offset
        
        draw_rounded_card(d, [card_x, card_y, card_x + card_w, card_y + card_h], fill=COLOR_CARD_ELV, outline=COLOR_CARD_ELV_BORDER)
        d.text((card_x + 18, card_y + 16), ctitle, font=font_h3, fill=ccolor)
        
        # Word wrap description
        words = cdesc.split()
        line1 = " ".join(words[:6])
        line2 = " ".join(words[6:])
        d.text((card_x + 18, card_y + 46), line1, font=font_body_sm, fill=COLOR_TEXT_2)
        d.text((card_x + 18, card_y + 68), line2, font=font_body_sm, fill=COLOR_TEXT_2)
        
    draw_bottom_bar(d, frame_in_scene)
    return im

def render_scene_2(frame_in_scene):
    """Scene 2: LangGraph-Style Directed Graph & State Engine (4s - 8s)."""
    im = BASE_CANVAS.copy()
    d = ImageDraw.Draw(im)
    t = frame_in_scene / 120.0
    
    draw_header(d, "STATE ENGINE", "LangGraph-Style Stateful Directed Graph Engine",
                "Deterministic state progression with immutable RunState, checkpointing & cyclic recovery", 1)
    
    # 4 Graph Nodes horizontally
    nodes = [
        ("Start Prompt", "User Goal Spec", "REQUIREMENT", COLOR_BRAND_RED),
        ("RunState", "Checkpoint DB/JSON", "PERSISTENCE", COLOR_INDIGO),
        ("EdgeRule Engine", "Conditional Routing", "GRAPH ROUTING", COLOR_CYAN),
        ("Bounded Recovery", "Fixer Cyclic Loop", "RECOVERY LOOP", COLOR_AMBER)
    ]
    
    node_w = 230
    node_h = 130
    node_y = 200
    spacing = 65
    start_x = (WIDTH - (len(nodes) * node_w + (len(nodes) - 1) * spacing)) // 2
    
    node_positions = []
    for i, (ntitle, nsub, ntag, ncolor) in enumerate(nodes):
        nx = start_x + i * (node_w + spacing)
        node_positions.append((nx, node_y, nx + node_w, node_y + node_h))
        
        # Determine active glow based on time
        phase = (t * 3.5) % 4.0
        is_active = (int(phase) == i)
        bg = (28, 40, 68) if is_active else COLOR_CARD_ELV
        outline = ncolor if is_active else COLOR_CARD_BORDER
        
        draw_rounded_card(d, [nx, node_y, nx + node_w, node_y + node_h], fill=bg, outline=outline, radius=14)
        
        # Step number & Tag
        d.text((nx + 16, node_y + 14), f"STEP 0{i+1}", font=font_badge, fill=COLOR_TEXT_3)
        draw_badge(d, nx + 120, node_y + 12, ntag, color=ncolor, bg=(ncolor[0], ncolor[1], ncolor[2], 25))
        
        # Title & Subtitle
        d.text((nx + 16, node_y + 48), ntitle, font=font_h2, fill=COLOR_TEXT_1)
        d.text((nx + 16, node_y + 82), nsub, font=font_body_sm, fill=COLOR_TEXT_2)
        
        # Active pulse indicator
        if is_active:
            d.ellipse([nx + node_w - 24, node_y + node_h - 24, nx + node_w - 12, node_y + node_h - 12], fill=ncolor)
    
    # Draw Directed Connecting Edges with traveling packets
    for i in range(len(nodes) - 1):
        x1 = node_positions[i][2]
        y1 = node_y + node_h // 2
        x2 = node_positions[i+1][0]
        y2 = y1
        
        # Base line
        d.line([(x1, y1), (x2, y2)], fill=(45, 60, 90), width=2)
        # Arrowhead
        d.polygon([(x2 - 8, y2 - 6), (x2, y2), (x2 - 8, y2 + 6)], fill=(80, 105, 150))
        
        # Animated packet
        packet_cycle = (t * 2.5 + i * 0.33) % 1.0
        px = x1 + int((x2 - x1) * packet_cycle)
        d.ellipse([px - 4, y1 - 4, px + 4, y1 + 4], fill=COLOR_CYAN)
    
    # Draw Cyclic Recovery Loop (Node 4 back to Node 3)
    loop_x1 = node_positions[3][0] + node_w // 2
    loop_y1 = node_y + node_h
    loop_x2 = node_positions[2][0] + node_w // 2
    loop_y2 = loop_y1
    
    # Arc down and back
    arc_y = loop_y1 + 55
    d.line([(loop_x1, loop_y1), (loop_x1, arc_y)], fill=COLOR_AMBER, width=2)
    d.line([(loop_x1, arc_y), (loop_x2, arc_y)], fill=COLOR_AMBER, width=2)
    d.line([(loop_x2, arc_y), (loop_x2, loop_y2)], fill=COLOR_AMBER, width=2)
    d.polygon([(loop_x2 - 6, loop_y2 + 8), (loop_x2, loop_y2), (loop_x2 + 6, loop_y2 + 8)], fill=COLOR_AMBER)
    
    # Cyclic loop text
    d.text((node_positions[2][2] - 50, arc_y - 18), "Cyclic Feedback: fix_attempts <= 3", font=font_mono_sm, fill=COLOR_AMBER)
    
    # Bottom Architecture Summary Card
    info_y = 440
    draw_rounded_card(d, [start_x, info_y, start_x + (len(nodes) * node_w + (len(nodes) - 1) * spacing), info_y + 190],
                      fill=COLOR_CARD_BG, outline=COLOR_CARD_BORDER)
    
    d.text((start_x + 24, info_y + 20), "CORE STATE MACHINE INVARIANTS", font=font_h3, fill=COLOR_BRAND_RED)
    
    bullets = [
        ("Immutable RunState Container:", "Thread-safe context dictionary recording agent outputs, artifacts, and test assertions."),
        ("Transactional Checkpointing:", "Persisted to .lara-orcest/checkpoints/{run_id}.json or database for instant --resume."),
        ("Deterministic EdgeRules:", "Pure callable rules evaluating outputs to choose next node or trigger cyclic bug repairs."),
        ("Zero State Bleed:", "Strict isolation between execution waves preventing accidental cross-agent contamination.")
    ]
    
    for idx, (b_title, b_desc) in enumerate(bullets):
        by = info_y + 55 + idx * 30
        d.text((start_x + 24, by), "•", font=font_h3, fill=COLOR_CYAN)
        d.text((start_x + 42, by), b_title, font=font_h3, fill=COLOR_TEXT_1)
        tb_w = font_h3.getbbox(b_title)[2] - font_h3.getbbox(b_title)[0]
        d.text((start_x + 50 + tb_w, by), b_desc, font=font_body, fill=COLOR_TEXT_2)
        
    draw_bottom_bar(d, 120 + frame_in_scene)
    return im

def render_scene_3(frame_in_scene):
    """Scene 3: 9 Specialized Pod Roles & Parallel Waves (8s - 12s)."""
    im = BASE_CANVAS.copy()
    d = ImageDraw.Draw(im)
    t = frame_in_scene / 120.0
    
    draw_header(d, "AGENT ROSTER", "Specialized Agent Pod & Parallel Wave Scheduling",
                "9 specialized roles with strict JSON schemas, least-privilege tools, and concurrent execution", 2)
    
    # Layout: Sequential lane -> Parallel Wave Box -> Sequential Finalization
    col_w = 340
    start_x = 50
    spacing = 40
    
    # Column 1: Analysis & Requirements (BA + PM + Scraper)
    col1_x = start_x
    draw_rounded_card(d, [col1_x, 170, col1_x + col_w, 620], fill=COLOR_CARD_BG, outline=COLOR_CARD_BORDER)
    d.text((col1_x + 18, 185), "PHASE 1: DOMAIN ANALYSIS", font=font_h3, fill=COLOR_BRAND_RED)
    
    c1_agents = [
        ("business_analyst", "Domain & Systems Analyst", "Extracts business invariants, tenant rules & edge cases.", "📋", COLOR_BRAND_RED),
        ("product_manager", "Technical Product Manager", "Builds ordered backlog annotated with scope_paths.", "🎯", COLOR_INDIGO),
        ("web_scraping_agent", "2-Site Ingestion Specialist", "Conditional extraction from 2 target URLs.", "🌐", COLOR_CYAN)
    ]
    
    for i, (aname, atitle, adesc, aico, acolor) in enumerate(c1_agents):
        ay = 225 + i * 125
        draw_rounded_card(d, [col1_x + 14, ay, col1_x + col_w - 14, ay + 110], fill=COLOR_CARD_ELV, outline=COLOR_CARD_ELV_BORDER)
        d.text((col1_x + 28, ay + 14), aico, font=font_h2, fill=COLOR_TEXT_1)
        d.text((col1_x + 65, ay + 14), aname, font=font_mono, fill=acolor)
        d.text((col1_x + 28, ay + 44), atitle, font=font_body_sm, fill=COLOR_TEXT_1)
        d.text((col1_x + 28, ay + 68), adesc, font=font_body_sm, fill=COLOR_TEXT_2)
    
    # Column 2: Architecture & Parallel Wave Dev (The Star Feature!)
    col2_x = col1_x + col_w + spacing
    draw_rounded_card(d, [col2_x, 170, col2_x + 440, 620], fill=COLOR_CARD_BG, outline=COLOR_BRAND_RED)
    
    # Header of parallel wave
    d.text((col2_x + 18, 185), "PHASE 2: ARCHITECTURE & PARALLEL WAVE", font=font_h3, fill=COLOR_CYAN)
    
    # Architect box
    draw_rounded_card(d, [col2_x + 14, 225, col2_x + 426, 310], fill=COLOR_CARD_ELV, outline=COLOR_CARD_ELV_BORDER)
    d.text((col2_x + 28, 238), "🏛️", font=font_h2, fill=COLOR_TEXT_1)
    d.text((col2_x + 65, 238), "architect  (Senior Laravel Architect)", font=font_mono, fill=COLOR_BRAND_RED)
    d.text((col2_x + 28, 272), "Translates backlog into blueprint: migrations, models, actions, controllers & tests.", font=font_body_sm, fill=COLOR_TEXT_2)
    
    # Glowing Parallel Wave Container
    pw_y = 330
    pulse_alpha = int(40 + 25 * math.sin(t * math.pi * 3))
    draw_rounded_card(d, [col2_x + 14, pw_y, col2_x + 426, 605], fill=(22, 34, 60), outline=(6, 182, 212, 180))
    d.text((col2_x + 28, pw_y + 12), "⚡ DETERMINISTIC PARALLEL EXECUTION WAVE", font=font_badge, fill=COLOR_CYAN)
    
    pw_agents = [
        ("database_agent", "database/*, app/Models/*", "Schema migrations, tenant indexes, model relations.", "💾", COLOR_BRAND_RED),
        ("backend_agent", "app/Http/*, routes/*", "API controllers, form requests, policies, actions.", "⚙️", COLOR_INDIGO),
        ("swagger_agent", "docs/swagger.json", "OpenAPI 3.0+ YAML/JSON specification documentation.", "📜", COLOR_EMERALD)
    ]
    
    for i, (aname, ascope, adesc, aico, acolor) in enumerate(pw_agents):
        ay = pw_y + 36 + i * 78
        draw_rounded_card(d, [col2_x + 26, ay, col2_x + 414, ay + 68], fill=COLOR_CARD_ELV, outline=acolor)
        d.text((col2_x + 38, ay + 12), aico, font=font_h3, fill=COLOR_TEXT_1)
        d.text((col2_x + 70, ay + 12), aname, font=font_mono, fill=acolor)
        d.text((col2_x + 220, ay + 14), f"[{ascope}]", font=font_mono_sm, fill=COLOR_CYAN)
        d.text((col2_x + 70, ay + 38), adesc, font=font_body_sm, fill=COLOR_TEXT_2)
    
    # Column 3: Quality Assurance, Review & Release
    col3_x = col2_x + 440 + spacing
    draw_rounded_card(d, [col3_x, 170, col3_x + col_w, 620], fill=COLOR_CARD_BG, outline=COLOR_CARD_BORDER)
    d.text((col3_x + 18, 185), "PHASE 3: QA & AUDIT RELEASE", font=font_h3, fill=COLOR_EMERALD)
    
    c3_agents = [
        ("qa_agent & fixer_agent", "Automated QA & Cyclic Repair", "Executes Pest/PHPUnit test runner within fix budget.", "🧪", COLOR_AMBER),
        ("reviewer_agent", "Staff Reviewer & Auditor", "PSR-12, Laravel security policies & edge case sign-off.", "🛡️", COLOR_INDIGO),
        ("git_agent", "Feature Branch Milestone", "Stages artifacts, feature branch commit & Project Memory.", "🚀", COLOR_EMERALD)
    ]
    
    for i, (aname, atitle, adesc, aico, acolor) in enumerate(c3_agents):
        ay = 225 + i * 125
        draw_rounded_card(d, [col3_x + 14, ay, col3_x + col_w - 14, ay + 110], fill=COLOR_CARD_ELV, outline=COLOR_CARD_ELV_BORDER)
        d.text((col3_x + 28, ay + 14), aico, font=font_h2, fill=COLOR_TEXT_1)
        d.text((col3_x + 65, ay + 14), aname, font=font_mono, fill=acolor)
        d.text((col3_x + 28, ay + 44), atitle, font=font_body_sm, fill=COLOR_TEXT_1)
        d.text((col3_x + 28, ay + 68), adesc, font=font_body_sm, fill=COLOR_TEXT_2)
        
    draw_bottom_bar(d, 240 + frame_in_scene)
    return im

def render_scene_4(frame_in_scene):
    """Scene 4: Sandboxed Tools, PathGuard & HITL Review Gates (12s - 16s)."""
    im = BASE_CANVAS.copy()
    d = ImageDraw.Draw(im)
    t = frame_in_scene / 120.0
    
    draw_header(d, "SECURITY & HITL", "Sandboxed Tools, PathGuard Guardrails & HITL Gates",
                "Defense-in-depth security: strict path sandboxing, Artisan whitelisting, and Human Review Gates", 3)
    
    # 3 Security Architecture Pillars
    col_w = 360
    start_x = (WIDTH - (3 * col_w + 2 * 35)) // 2
    spacing = 35
    col_y = 175
    col_h = 240
    
    pillars = [
        ("PathGuard Sandbox", "FILESYSTEM ISOLATION", COLOR_BRAND_RED, [
            ("✓ ALLOWED", "app/, database/, routes/, tests/, docs/"),
            ("✗ BLOCKED", "vendor/, .env, /etc/, composer.lock"),
            ("ENFORCED", "Realpath normalization prevents directory traversal")
        ]),
        ("Artisan Command Runner", "COMMAND WHITELIST", COLOR_CYAN, [
            ("✓ ALLOWED", "migrate, test, route:list, make:*"),
            ("✗ BLOCKED", "db:wipe, tinker, raw bash execution"),
            ("ENFORCED", "Whitelisted array check before Process invocation")
        ]),
        ("Git Branch Isolation", "REPOSITORY INTEGRITY", COLOR_EMERALD, [
            ("✓ ALLOWED", "Isolated orcest/feature-... branches"),
            ("✗ BLOCKED", "Direct commits to main / master"),
            ("ENFORCED", "Working tree diff verification before milestone")
        ])
    ]
    
    for i, (ptitle, pbadge, pcolor, items) in enumerate(pillars):
        px = start_x + i * (col_w + spacing)
        draw_rounded_card(d, [px, col_y, px + col_w, col_y + col_h], fill=COLOR_CARD_ELV, outline=pcolor)
        
        # Pillar Header
        draw_badge(d, px + 18, col_y + 16, pbadge, color=pcolor, bg=(pcolor[0], pcolor[1], pcolor[2], 25))
        d.text((px + 18, col_y + 48), ptitle, font=font_h2, fill=COLOR_TEXT_1)
        
        # Pillar Items
        for j, (tag, detail) in enumerate(items):
            iy = col_y + 88 + j * 46
            color_tag = COLOR_EMERALD if "✓" in tag else (COLOR_BRAND_RED if "✗" in tag else COLOR_TEXT_3)
            d.text((px + 18, iy), tag, font=font_mono, fill=color_tag)
            d.text((px + 18, iy + 20), detail, font=font_body_sm, fill=COLOR_TEXT_2)
            
    # Human-in-the-Loop (HITL) Interactive Approval Modal (Lower section)
    modal_y = 445
    modal_w = 3 * col_w + 2 * spacing
    draw_rounded_card(d, [start_x, modal_y, start_x + modal_w, modal_y + 195], fill=(18, 26, 44), outline=COLOR_INDIGO)
    
    # Modal Header
    draw_badge(d, start_x + 24, modal_y + 18, "HUMAN-IN-THE-LOOP GATE (HITL)", color=COLOR_INDIGO, bg=(99, 102, 241, 30))
    d.text((start_x + 285, modal_y + 18), "after_plan  •  after_code  •  opt-out with --no-human-review", font=font_mono_sm, fill=COLOR_TEXT_3)
    
    d.text((start_x + 24, modal_y + 52), "Interactive Review: Software Architect Technical Plan Ready", font=font_h2, fill=COLOR_TEXT_1)
    
    desc_modal = "LaraOrcest paused execution after Software Architect generated 14 proposed files (migrations, models, form requests, controllers).\nHuman operator reviews diff and issues approval or routes specific revision feedback to the agents."
    d.text((start_x + 24, modal_y + 86), desc_modal, font=font_body, fill=COLOR_TEXT_2)
    
    # Action buttons simulation
    btn_w = 200
    btn_h = 42
    
    # Approve button
    btn_approve_x = start_x + 24
    btn_y = modal_y + 138
    d.rounded_rectangle([btn_approve_x, btn_y, btn_approve_x + btn_w, btn_y + btn_h], radius=8, fill=COLOR_EMERALD)
    d.text((btn_approve_x + 22, btn_y + 11), "✓  APPROVE PLAN", font=font_h3, fill=(255, 255, 255))
    
    # Reject / Feedback button
    btn_reject_x = btn_approve_x + btn_w + 20
    d.rounded_rectangle([btn_reject_x, btn_y, btn_reject_x + 240, btn_y + btn_h], radius=8, fill=COLOR_CARD_ELV, outline=COLOR_BRAND_RED)
    d.text((btn_reject_x + 18, btn_y + 11), "✗  REJECT WITH FEEDBACK", font=font_h3, fill=COLOR_BRAND_RED)
    
    # Auto-approve notice
    d.text((btn_reject_x + 270, btn_y + 13), "CI/CD Mode: use --no-human-review for fully autonomous execution", font=font_mono_sm, fill=COLOR_CYAN)
    
    draw_bottom_bar(d, 360 + frame_in_scene)
    return im

def render_scene_5(frame_in_scene):
    """Scene 5: Developer Experience & Artisan CLI Workflows (16s - 20s)."""
    im = BASE_CANVAS.copy()
    d = ImageDraw.Draw(im)
    t = frame_in_scene / 120.0
    
    draw_header(d, "DEVELOPER EXPERIENCE", "Familiar Laravel Artisan Commands & Live Execution",
                "Natural language prompt-to-code execution with live progress reporting and run inspection", 4)
    
    # macOS-style Dark Developer Terminal Window
    term_x = 70
    term_y = 175
    term_w = WIDTH - 140
    term_h = 465
    
    # Window Frame & Titlebar
    draw_rounded_card(d, [term_x, term_y, term_x + term_w, term_y + term_h], fill=(12, 16, 26), outline=(35, 48, 72), radius=10)
    
    # Window Titlebar
    d.rectangle([term_x, term_y, term_x + term_w, term_y + 36], fill=(18, 24, 38))
    d.line([(term_x, term_y + 36), (term_x + term_w, term_y + 36)], fill=(35, 48, 72), width=1)
    
    # macOS window control buttons
    d.ellipse([term_x + 15, term_y + 12, term_x + 27, term_y + 24], fill=(239, 68, 68))
    d.ellipse([term_x + 35, term_y + 12, term_x + 47, term_y + 24], fill=(245, 158, 11))
    d.ellipse([term_x + 55, term_y + 12, term_x + 67, term_y + 24], fill=(16, 185, 129))
    
    # Title text
    d.text((term_x + term_w//2 - 120, term_y + 10), "yousef@laravel-app: ~/projects/saas-core", font=font_mono_sm, fill=COLOR_TEXT_3)
    
    # Terminal Content Stream
    lines = [
        ("$ php artisan orcest:build \"Add multi-vendor marketplace with tenant isolation\" --dry-run", COLOR_CYAN, 0),
        ("┌ LaraOrcest Orchestrator v1.0 ────────────────────────────────────────────────────────┐", COLOR_TEXT_3, 5),
        ("│ Run ID: run_a92f-4c10 • Strategy: graph • Memory: .lara-orcest/memory.json (Loaded)   │", COLOR_TEXT_2, 10),
        ("│                                                                                      │", COLOR_TEXT_3, 12),
        ("│ [01/08] business_analyst   -> 6 domain invariants & authorization rules extracted [✓]│", COLOR_BRAND_RED, 20),
        ("│ [02/08] product_manager    -> 4 backlog items decomposed with scope_paths         [✓]│", COLOR_INDIGO, 35),
        ("│ [03/08] architect          -> technical plan designed (14 files proposed)        [✓]│", COLOR_TEXT_1, 50),
        ("│ [04/08] PARALLEL WAVE      -> DatabaseAgent & BackendAgent concurrent execution   [⚡]│", COLOR_CYAN, 65),
        ("│ [05/08] swagger_agent      -> docs/swagger.json OpenAPI 3.0 specification updated [✓]│", COLOR_EMERALD, 80),
        ("│ [06/08] qa_agent           -> 22 Pest tests generated; 100% assertions passing    [✓]│", COLOR_AMBER, 95),
        ("│ [07/08] reviewer_agent     -> PSR-12, tenant isolation & security audit approved  [✓]│", COLOR_INDIGO, 105),
        ("│ [08/08] git_agent          -> milestone commit on branch orcest/multi-vendor     [🚀]│", COLOR_EMERALD, 115),
        ("└──────────────────────────────────────────────────────────────────────────────────────┘", COLOR_TEXT_3, 118)
    ]
    
    ty_start = term_y + 54
    for idx, (ttext, tcolor, appear_frame) in enumerate(lines):
        if frame_in_scene >= appear_frame:
            line_y = ty_start + idx * 24
            d.text((term_x + 24, line_y), ttext, font=font_mono, fill=tcolor)
            
    # Secondary command callout badge (bottom right)
    if frame_in_scene >= 60:
        callout_x = term_x + term_w - 420
        callout_y = term_y + term_h - 90
        draw_rounded_card(d, [callout_x, callout_y, callout_x + 400, callout_y + 70], fill=(22, 34, 58), outline=COLOR_CYAN)
        d.text((callout_x + 16, callout_y + 12), "$ php artisan orcest:status --run-id=run_a92f", font=font_mono_sm, fill=COLOR_CYAN)
        d.text((callout_x + 16, callout_y + 38), "Status: COMPLETED (Checkpoints: 8 | Memory: Consolidated)", font=font_body_sm, fill=COLOR_EMERALD)
        
    draw_bottom_bar(d, 480 + frame_in_scene)
    return im

def render_scene_6(frame_in_scene):
    """Scene 6: Production Ecosystem & Quickstart Call-to-Action (20s - 24s)."""
    im = BASE_CANVAS.copy()
    d = ImageDraw.Draw(im)
    t = frame_in_scene / 120.0
    
    draw_header(d, "ECOSYSTEM", "Production-Ready Multi-Agent Framework for Laravel",
                "Built exclusively for PHP 8.2+ with zero Python overhead and OpenAI-compatible proxy routing", 5)
    
    # 4 Feature Grid Cards
    cards = [
        ("Memorilla Project Memory", "Indexed Codebase Wisdom",
         ".lara-orcest/memory.json stores conventions, schema summaries & past bug resolutions.", "🧠", COLOR_BRAND_RED),
        ("Multi-Tenant DDD Ready", "Domain-Driven Design",
         "Strict TenantScope isolation across Bounded Contexts (Tenancy, Catalog, Orders).", "🏢", COLOR_INDIGO),
        ("OpenAI & LiteLLM Native", "Flexible Model Routing",
         "Route complex tasks to Claude/GPT-4o while using fast models for code generation.", "⚡", COLOR_CYAN),
        ("VitePress Documentation", "Filament + Next.js Aesthetic",
         "Modern low-glare documentation site with interactive pipelines & vector line art.", "📚", COLOR_EMERALD)
    ]
    
    grid_w = 560
    grid_h = 135
    col1_x = 70
    col2_x = WIDTH - 70 - grid_w
    row1_y = 175
    row2_y = 330
    
    coords = [(col1_x, row1_y), (col2_x, row1_y), (col1_x, row2_y), (col2_x, row2_y)]
    
    for (cx, cy), (ctitle, ctag, cdesc, cico, ccolor) in zip(coords, cards):
        draw_rounded_card(d, [cx, cy, cx + grid_w, cy + grid_h], fill=COLOR_CARD_ELV, outline=COLOR_CARD_BORDER)
        draw_badge(d, cx + 18, cy + 16, ctag, color=ccolor, bg=(ccolor[0], ccolor[1], ccolor[2], 25))
        d.text((cx + 18, cy + 46), f"{cico}  {ctitle}", font=font_h2, fill=COLOR_TEXT_1)
        d.text((cx + 18, cy + 86), cdesc, font=font_body, fill=COLOR_TEXT_2)
    
    # Center Call to Action Banner
    cta_y = 490
    cta_w = WIDTH - 140
    cta_h = 145
    draw_rounded_card(d, [col1_x, cta_y, col1_x + cta_w, cta_y + cta_h], fill=(18, 26, 44), outline=COLOR_BRAND_RED)
    
    d.text((col1_x + 30, cta_y + 20), "INSTALL VIA COMPOSER & START ORCHESTRATING", font=font_badge, fill=COLOR_BRAND_RED)
    
    # Composer command block
    d.rounded_rectangle([col1_x + 30, cta_y + 48, col1_x + 580, cta_y + 98], radius=8, fill=(10, 14, 24), outline=COLOR_CARD_BORDER)
    d.text((col1_x + 48, cta_y + 64), "$ composer require yousef/lara-orcest", font=font_mono_lg, fill=COLOR_CYAN)
    
    # Docs link block
    d.rounded_rectangle([col1_x + 605, cta_y + 48, col1_x + cta_w - 30, cta_y + 98], radius=8, fill=(10, 14, 24), outline=COLOR_CARD_BORDER)
    d.text((col1_x + 625, cta_y + 64), "🌐 https://yousefhatem12.github.io/LaraOrcest", font=font_mono, fill=COLOR_TEXT_1)
    
    d.text((col1_x + 30, cta_y + 115), "Open Source under MIT License  •  Authored by Yousef Hatem  •  GitHub: yousefhatem12/LaraOrcest", font=font_body_sm, fill=COLOR_TEXT_3)
    
    draw_bottom_bar(d, 600 + frame_in_scene)
    return im

def main():
    print(f"=== LaraOrcest Silent Explainer Video Generator ===")
    print(f"Target: {OUTPUT_FILE}")
    print(f"Resolution: {WIDTH}x{HEIGHT} @ {FPS}fps (Total: {TOTAL_FRAMES} frames = {NUM_SCENES * SCENE_DURATION_SEC}s)")
    print(f"Audio: Explicitly NONE (-an)")
    
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    
    # FFmpeg Command Pipeline (rawvideo via stdin)
    ffmpeg_cmd = [
        "ffmpeg", "-y",
        "-f", "rawvideo",
        "-pix_fmt", "rgb24",
        "-s", f"{WIDTH}x{HEIGHT}",
        "-r", str(FPS),
        "-i", "-",
        "-c:v", "libopenh264",
        "-b:v", "2200k",
        "-pix_fmt", "yuv420p",
        "-an",
        OUTPUT_FILE
    ]
    
    print(f"Starting ffmpeg pipeline...")
    proc = subprocess.Popen(ffmpeg_cmd, stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    
    t_start = time.time()
    
    scene_renderers = [
        render_scene_1,
        render_scene_2,
        render_scene_3,
        render_scene_4,
        render_scene_5,
        render_scene_6
    ]
    
    frames_per_scene = FPS * SCENE_DURATION_SEC  # 120 frames
    
    for scene_idx, renderer in enumerate(scene_renderers):
        print(f"Rendering Scene {scene_idx + 1}/{NUM_SCENES}...")
        for f in range(frames_per_scene):
            im = renderer(f)
            proc.stdin.write(im.tobytes())
            
    proc.stdin.close()
    stderr = proc.stderr.read().decode()
    proc.wait()
    
    if proc.returncode != 0:
        print(f"Error: ffmpeg exited with code {proc.returncode}:\n{stderr}")
        sys.exit(1)
        
    duration = time.time() - t_start
    file_size_mb = os.path.getsize(OUTPUT_FILE) / (1024 * 1024)
    print(f"\n[✓] Video generated successfully in {duration:.2f}s!")
    print(f"File: {OUTPUT_FILE} ({file_size_mb:.2f} MB)")

if __name__ == "__main__":
    main()
