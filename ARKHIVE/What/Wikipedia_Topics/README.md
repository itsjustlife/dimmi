# Wikipedia Topics Overlay

This branch mirrors the top layer of [Wikipedia's contents structure](https://en.wikipedia.org/wiki/Wikipedia:Contents) so the Arkhive (and Door) can reuse familiar navigation categories. Each folder below introduces one high-level topic area. You can drill down by adding Markdown notes, outlines, or further folders inside each area.

## How it fits the Mind Atlas
- **Alignment with What** – These folders live under the `WHAT` branch because they organise knowledge domains and subject matter.
- **Bridges to other branches** – When adding details, link to relevant WHO, WHERE, WHEN, WHY, or HOW entries so Door surfaces cross-branch context.
- **Security / access** – Door’s node builder reads any Markdown inside `ARKHIVE/` (except README duplicates). Using this directory keeps permissions consistent with the rest of the Arkhive tree; no extra configuration is needed.

## Top-level topic areas
- [General Reference](General_Reference/)
- [Culture and the Arts](Culture_and_the_Arts/)
- [Geography and Places](Geography_and_Places/)
- [Health and Medicine](Health_and_Medicine/)
- [History and Events](History_and_Events/)
- [Human Activities](Human_Activities/)
- [Mathematics and Logic](Mathematics_and_Logic/)
- [Natural and Physical Sciences](Natural_and_Physical_Sciences/)
- [People and Self](People_and_Self/)
- [Philosophy and Thinking](Philosophy_and_Thinking/)
- [Religion and Belief Systems](Religion_and_Belief_Systems/)
- [Society and Social Sciences](Society_and_Social_Sciences/)
- [Technology and Applied Sciences](Technology_and_Applied_Sciences/)

Add new folders or notes under whichever heading fits best. Door will pick them up automatically the next time we rebuild its node cache.
