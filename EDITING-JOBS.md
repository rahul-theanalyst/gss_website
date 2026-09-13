# How to update job openings on the GSS Careers page

You only ever edit **one file: `jobs.json`**.

You never need to touch the design, the layout, the colours, or any HTML.
The Careers page reads `jobs.json` every time someone loads it and builds the
list of openings automatically.

---

## Before you start

- Open `jobs.json` in **Notepad** (Windows), **TextEdit** (Mac), or **VS Code**.
  Do **not** open it in Word — Word adds invisible formatting that breaks the file.
- Make a copy of the file before your first edit, so you can go back if needed.

---

## What one opening looks like

Everything between `{` and `}` is **one job**:

```json
{
  "title": "Data Analyst",
  "location": "Hyderabad, India",
  "type": "Full Time",
  "experience": "1–3 Years",
  "department": "Data & Analytics",
  "description": "Short paragraph describing the role.",
  "skills": ["SQL", "Excel", "Power BI"],
  "applyLink": "contact.html",
  "featured": false
}
```

### What each line means

| Field | What it does | Example |
|---|---|---|
| `title` | The job title. Shown in the first column. | `"Senior Power BI Developer"` |
| `location` | Shown in the second column, and used by the Location filter. | `"Houston, TX"` or `"Remote"` |
| `type` | Shown in the third column, and used by the **Work model** dropdown. | `"On-site"`, `"Hybrid"`, `"Remote"`, `"Contract"`, `"Full Time"` |
| `experience` | Shown when a visitor opens the role. | `"4–7 Years"` |
| `department` | Shown when a visitor opens the role, and searchable. | `"Technology & Delivery"` |
| `description` | The paragraph a visitor reads after clicking **View Role**. | Any sentence or two. |
| `skills` | The pills under the description. A list, in square brackets. | `["SQL", "Python"]` |
| `applyLink` | Where the **Apply for this role** button goes. | `"contact.html"` or a full web address, or `"mailto:careers@gss.com"` |
| `featured` | `true` pins the job to the top with a gold **Featured** tag. `false` for a normal job. | `false` |

> The **Work model** dropdown and the **Location** suggestions build themselves
> from whatever you type here. If you add a job with `"type": "Internship"`,
> "Internship" appears in the dropdown automatically.

---

## Add a new opening

1. Open `jobs.json`.
2. Find the **last** job block — it ends with `}` just before the `]`.
3. Add a comma `,` after that closing `}`.
4. Paste a copy of a whole job block underneath it.
5. Change the values inside the quotes to the new job's details.
6. Save the file and upload it to the website.

It should end up looking like this:

```json
    },
    {
      "title": "Your new job title",
      "location": "City, State",
      "type": "Hybrid",
      "experience": "2–5 Years",
      "department": "Data & Analytics",
      "description": "What this person will do.",
      "skills": ["Skill one", "Skill two"],
      "applyLink": "contact.html",
      "featured": false
    }
  ]
}
```

## Remove an opening

Delete the whole block from its `{` to its `}` — **and** the comma that sits
between it and the job next to it. Every job must be separated by exactly one
comma, and the last job must have **no** comma after its `}`.

## Change an opening

Just edit the text inside the quotes. Nothing else needs to change.

## Close all openings temporarily

Leave the list empty, like this:

```json
{
  "jobs": []
}
```

The page then shows a polished **"No current openings"** message with an
invitation to submit a profile — it never looks broken or blank.

---

## The three rules that keep the file valid

1. **Every value stays inside double quotes** — `"Remote"`, not `Remote`.
   The only exceptions are `featured`, which is `true` or `false` with no
   quotes, and the `skills` list, which uses square brackets.
2. **A comma between every job**, and **no comma after the last one**.
3. **Don't delete** the `{`, `}`, `[` or `]` that wrap the whole file.

If you use a curly quote (`"`) instead of a straight one (`"`), the file breaks.
This is why Word should not be used.

### Check your work before uploading

Paste the whole file into <https://jsonlint.com> and press **Validate JSON**.
Green means you're safe to upload. Red tells you which line has the problem.

---

## After you upload

Open the Careers page and press **Ctrl + Shift + R** (Windows) or
**Cmd + Shift + R** (Mac) to force a fresh load. Your changes should appear
immediately.

---

## Notes for whoever maintains the site

- `careers.js` renders the board. `PAGE_SIZE` at the top of that file controls
  how many rows show before the **View All Openings** button appears (currently 8).
- The loader accepts either `{ "jobs": [ ... ] }` or a bare `[ ... ]` array.
- Any opening can be linked to directly: `career.html#job-senior-power-bi-developer`
  (the anchor is the job title, lowercased with hyphens instead of spaces). The
  page opens that role's detail panel and scrolls to it — handy for pasting a
  single role into an email or a LinkedIn post.
- `jobs.json` is fetched with `cache: "no-store"`, so edits go live without a
  cache purge.
- **This needs the site to be served over http/https.** Opening `career.html`
  straight off the disk (a `file:///` address) blocks the fetch, and the page
  will show its "taking a moment to load" message. That is expected — it works
  normally once the files are on the web server.
- To move to WordPress or a CMS later, point `DATA_URL` in `careers.js` at an
  endpoint that returns the same field names. No HTML or CSS changes required.
