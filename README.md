# Question Press

Question Press is a comprehensive WordPress plugin designed to create a complete question practice system. It allows administrators to import, create, and manage a large bank of questions, and provides a fully interactive frontend for users to practice and track their progress.

## Features

- **Advanced Admin Dashboard:** A full suite of tools to manage every aspect of your question bank.
  - **All Questions View:** A powerful list table with searching, sorting, and filtering by subject or label.
  - **Trash System:** A complete trash/restore/delete permanently workflow.
  - **Quick Edit:** Make changes to questions directly from the list view without a full page reload.
  - **Detailed Question Editor:** An advanced editor to add or edit question groups, with support for multiple questions under a single direction passage.
  - **Per-Question Labeling:** Assign multiple, color-coded labels to individual questions.
  - **Custom Question IDs:** Each question gets a unique, sequential ID starting from 1000 for easy reference.
- **Import & Export Engine:**
  - **Import:** Easily import questions in bulk using a `.zip` package containing a `questions.json` file. The importer automatically handles duplicates and creates new subjects as needed.
  - **Export:** Back up your questions by exporting them to a `.zip` package, filtered by subject.
- **Interactive Frontend:**
  - **Practice Shortcode:** Simply add `[question_press_practice]` to any page to display the practice area.
  - **Custom Sessions:** Users can select subjects and configure settings like "PYQ Only," timers, and scoring.
  - **Revision Mode:** Allows users to practice only the questions they have attempted before.
  - **AJAX-Powered:** The entire practice experience is smooth and fast, with no page reloads.
- **User Dashboard:**
  - **History Shortcode:** Add `[question_press_dashboard]` to a page to show users their personal practice history.
  - **Session Management:** Users can review past scores and delete their own session or revision history.
- **Question Press Kit Tool:**
  - A companion `.exe` tool for Windows to easily create the question packages for import, either manually or from a formatted `.docx` file.

---

## Installation

1.  Download the plugin's `.zip` file from the GitHub repository.
2.  In your WordPress admin dashboard, navigate to **Plugins -> Add New**.
3.  Click the **"Upload Plugin"** button at the top of the page.
4.  Choose the `.zip` file you downloaded and click **"Install Now"**.
5.  Once installed, click **"Activate Plugin"**.

---

## Usage

### Frontend Shortcodes

To allow your users to practice, create new pages in WordPress and add the following shortcodes to the page content:

-   **`[question_press_practice]`**
    -   This will display the main practice application where users can start a new session.

-   **`[question_press_dashboard]`**
    -   This will display the logged-in user's dashboard, showing a table of their past practice session results.

### Admin Panel

All administrative features can be found under the **"Question Press"** menu item in your WordPress dashboard.

---

## The "Question Press Kit" Tool

To create questions for import, use the `Question Press Kit.exe` tool. It has two modes:

1.  **Manual Entry:** Fill out the forms to add questions one by one to a queue, then generate the final `.zip` file.
2.  **Import from DOCX:** Create a `.docx` file using the specific format below, and the tool will parse it automatically.

### Required DOCX Format

Each block of related questions must be wrapped in `[start]` and `[end]` tags.

```
[start]
Direction: This is the common direction for the following questions. It is optional.
Q1: What is the first question text?
(1) Option A
(2*) This is the correct option (add an asterisk to mark it)
(3) Option C
(4) Option D
Q2: What is the second question in the same group?
(1*) Correct Option A
(2) Option B
[end]

[start]
Q3: This is a question with no direction.
(1) Option X
(2*) Option Y
[end]
```