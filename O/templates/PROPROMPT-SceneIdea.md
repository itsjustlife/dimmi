[⟵ START](../START.md) · [Arkhive](../Arkhive/Arkhive.md) · [Hub](../HUB/README.md)

# PROPROMPT · SceneIdea v1
**Use:** seed a single scene with visual, motion, and emotional continuity.  
**Outputs:** image boards, shot lists, or a one‑pager.

## Identity
- **Name:** {{scene_name}}
- **Logline (1–2 lines):** {{logline}}
- **Intention (what the scene must do):** {{intention}}

## SceneDNA  (continuity anchors)
- **Emotion arc:** {{emotion_arc}}
- **Sensory anchors (3–5 words):** {{sensory_words}}
- **Motifs/props:** {{motifs}}
- **Constraints (must avoid):** {{negatives}}

## World & Characters
- **Setting (time/place):** {{setting}}
- **Characters (roles + 1 trait each):** {{characters}}

## Visual Kinetic Sketching (VKS)
- **Camera stance:** {{wide/medium/close}}
- **Primary motion (verb):** {{pan/track/tilt/handheld}}
- **Gesture beats (3–5, one line each):**  
  1) {{beat1}}  
  2) {{beat2}}  
  3) {{beat3}}  

## PCP (Procedural Cinematic Prompting)
- **Beat list → shots:**  
  - Beat 1 → Shot type + lens + duration  
  - Beat 2 → Shot type + lens + duration

## Style
- **References (optional):** {{refs}}
- **Palette/lighting (tags):** {{palette_tags}}
- **Aspect ratios / deliverables:** {{ratios}}

---

### Actions
@@create: /assets/scene-boards/{{scene_name}}/
@@commit: Add {scene_name} to HUB/PROPROMPTS.md
