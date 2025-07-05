import tkinter as tk
from tkinter import ttk, filedialog, messagebox
import docx
import re
import json
import uuid
import os
import zipfile
import shutil
from datetime import datetime

class ReviewWindow(tk.Toplevel):
    def __init__(self, parent, question_queue):
        super().__init__(parent)
        self.parent = parent
        self.question_queue = question_queue

        self.title("Review and Export Questions")
        self.geometry("700x500")
        
        # --- Treeview to display questions ---
        columns = ('subject', 'direction', 'question')
        self.tree = ttk.Treeview(self, columns=columns, show='headings')
        
        self.tree.heading('subject', text='Subject')
        self.tree.heading('direction', text='Direction')
        self.tree.heading('question', text='Question')
        
        self.tree.column('subject', width=150)
        self.tree.column('direction', width=200)
        self.tree.column('question', width=350)
        
        for q in self.question_queue:
            dir_text = q['direction'][:50] + '...' if q['direction'] else 'N/A'
            self.tree.insert('', 'end', values=(q['subject'], dir_text, q['questionText']))
            
        self.tree.pack(padx=10, pady=10, expand=True, fill='both')

        # --- Action Buttons ---
        button_frame = ttk.Frame(self)
        button_frame.pack(padx=10, pady=10, fill='x')
        
        ttk.Button(button_frame, text="Generate ZIP File...", command=self.generate_zip).pack(side='right')
        ttk.Button(button_frame, text="Cancel", command=self.destroy).pack(side='right', padx=10)

    def generate_zip(self):
        if not self.question_queue:
            messagebox.showwarning("Warning", "The queue is empty. Nothing to generate.")
            return

        filepath = filedialog.asksaveasfilename(
            title="Save Question Package",
            defaultextension=".zip",
            filetypes=(("ZIP Archive", "*.zip"), ("All files", "*.*"))
        )
        if not filepath:
            return # User cancelled

        # --- Group questions by subject and direction ---
        grouped_data = {}
        for q in self.question_queue:
            group_key = (q['subject'], q['direction'])
            if group_key not in grouped_data:
                grouped_data[group_key] = []
            grouped_data[group_key].append(q)

        # --- Build the final JSON structure ---
        question_groups = []
        for (subject, direction_text), questions in grouped_data.items():
            group = {
                "groupId": str(uuid.uuid4()),
                "subject": subject,
                "Direction": {"text": direction_text, "image": None} if direction_text else None,
                "questions": []
            }
            for q in questions:
                question_entry = {
                    "questionId": str(uuid.uuid4()),
                    "questionText": q['questionText'],
                    "isPYQ": q['isPYQ'],
                    "options": q['options'],
                    "source": {"page": None, "number": None} # Not captured by tool
                }
                group["questions"].append(question_entry)
            question_groups.append(group)

        final_json = {
            "schemaVersion": "1.2",
            "exportTimestamp": datetime.utcnow().isoformat() + "Z",
            "sourceFile": os.path.basename(filepath).replace('.zip', ''),
            "questionGroups": question_groups
        }

        # --- Create ZIP file ---
        temp_dir = "temp_export"
        os.makedirs(temp_dir, exist_ok=True)
        
        json_path = os.path.join(temp_dir, "questions.json")
        with open(json_path, 'w', encoding='utf-8') as f:
            json.dump(final_json, f, indent=2)
            
        # Create the zip file
        with zipfile.ZipFile(filepath, 'w', zipfile.ZIP_DEFLATED) as zipf:
            zipf.write(json_path, os.path.basename(json_path))
        
        # Cleanup
        shutil.rmtree(temp_dir)
        
        messagebox.showinfo("Success", f"Successfully generated ZIP file at:\n{filepath}")
        self.parent.clear_queue()
        self.destroy()


class App(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("Question Press Kit")
        self.geometry("800x750")
        self.question_queue = []
        notebook = ttk.Notebook(self)
        notebook.pack(pady=10, padx=10, expand=True, fill="both")
        manual_entry_frame = ttk.Frame(notebook)
        docx_import_frame = ttk.Frame(notebook)
        manual_entry_frame.pack(fill="both", expand=True)
        docx_import_frame.pack(fill="both", expand=True)
        notebook.add(manual_entry_frame, text="Manual Entry")
        notebook.add(docx_import_frame, text="Import from DOCX")
        self.create_manual_entry_widgets(manual_entry_frame)
        self.create_docx_import_widgets(docx_import_frame)

    def create_manual_entry_widgets(self, parent_frame):
        group_frame = ttk.LabelFrame(parent_frame, text="Group Details (for one or more questions)", padding=(10, 5))
        group_frame.pack(padx=10, pady=10, fill="x")
        ttk.Label(group_frame, text="Subject:").grid(row=0, column=0, sticky="w", padx=5, pady=5)
        self.manual_subject_var = tk.StringVar()
        ttk.Entry(group_frame, textvariable=self.manual_subject_var, width=40).grid(row=0, column=1, sticky="ew", padx=5, pady=5)
        ttk.Label(group_frame, text="Direction Text (Optional):").grid(row=1, column=0, sticky="nw", padx=5, pady=5)
        self.direction_text = tk.Text(group_frame, height=4, width=60)
        self.direction_text.grid(row=1, column=1, columnspan=2, sticky="ew", padx=5, pady=5)
        ttk.Label(group_frame, text="Direction Image:").grid(row=0, column=2, sticky="w", padx=15, pady=5)
        self.dir_image_path_var = tk.StringVar()
        ttk.Button(group_frame, text="Upload Image...", command=self.upload_dir_image).grid(row=0, column=3, sticky="e", padx=5, pady=5)
        group_frame.columnconfigure(1, weight=1)
        question_frame = ttk.LabelFrame(parent_frame, text="Question Details", padding=(10, 5))
        question_frame.pack(padx=10, pady=5, fill="x")
        ttk.Label(question_frame, text="Question Text:").grid(row=0, column=0, sticky="nw", padx=5, pady=5)
        self.question_text = tk.Text(question_frame, height=4, width=60)
        self.question_text.grid(row=0, column=1, sticky="ew", padx=5, pady=5)
        self.is_pyq_var = tk.BooleanVar()
        ttk.Checkbutton(question_frame, text="Is this a PYQ (Previous Year Question)?", variable=self.is_pyq_var).grid(row=1, column=1, sticky="w", padx=5, pady=5)
        question_frame.columnconfigure(1, weight=1)
        options_frame = ttk.LabelFrame(parent_frame, text="Options (Mark the correct one)", padding=(10, 5))
        options_frame.pack(padx=10, pady=10, fill="x")
        self.correct_option_var = tk.IntVar(value=1)
        self.option_vars = []
        for i in range(5):
            ttk.Radiobutton(options_frame, text=f"Option {i+1} is correct", variable=self.correct_option_var, value=i+1).grid(row=i, column=0, sticky="w", padx=5, pady=2)
            option_var = tk.StringVar()
            ttk.Entry(options_frame, textvariable=option_var, width=80).grid(row=i, column=1, sticky="ew", padx=5, pady=2)
            self.option_vars.append(option_var)
        options_frame.columnconfigure(1, weight=1)
        action_frame = ttk.Frame(parent_frame)
        action_frame.pack(padx=10, pady=10, fill="x")
        ttk.Button(action_frame, text="Add Question to Queue", command=self.add_question_to_queue).pack(side="left")
        self.queue_status_var = tk.StringVar(value="0 questions in queue.")
        ttk.Label(action_frame, textvariable=self.queue_status_var, foreground="blue").pack(side="left", padx=20)
        ttk.Button(action_frame, text="Review & Generate File...", command=self.review_and_generate).pack(side="right")

    def add_question_to_queue(self):
        subject = self.manual_subject_var.get().strip()
        direction = self.direction_text.get("1.0", "end-1c").strip()
        question = self.question_text.get("1.0", "end-1c").strip()
        is_pyq = self.is_pyq_var.get()
        options = [var.get().strip() for var in self.option_vars if var.get().strip()]
        correct_option_index = self.correct_option_var.get() - 1
        if not subject or not question or len(options) < 2 or correct_option_index >= len(options):
            messagebox.showerror("Error", "Please fill all required fields correctly.")
            return
        question_data = {"subject": subject, "direction": direction, "questionText": question, "isPYQ": is_pyq, "options": [{"optionText": text, "isCorrect": i == correct_option_index} for i, text in enumerate(options)]}
        self.question_queue.append(question_data)
        messagebox.showinfo("Success", "Question added to the queue!")
        self.question_text.delete("1.0", "end")
        for var in self.option_vars:
            var.set("")
        self.is_pyq_var.set(False)
        self.correct_option_var.set(1)
        self.question_text.focus()
        self.queue_status_var.set(f"{len(self.question_queue)} questions in queue.")

    def upload_dir_image(self):
        messagebox.showinfo("Info", "Image upload functionality will be added in a future version.")

    def review_and_generate(self):
        if not self.question_queue:
            messagebox.showwarning("Warning", "The queue is empty. Please add questions first.")
            return
        ReviewWindow(self, self.question_queue)

    def clear_queue(self):
        self.question_queue.clear()
        self.queue_status_var.set("0 questions in queue.")

    def create_docx_import_widgets(self, parent_frame):
        file_frame = ttk.LabelFrame(parent_frame, text="1. Select File", padding=(10, 5))
        file_frame.pack(padx=10, pady=10, fill="x")
        self.docx_path_var = tk.StringVar()
        ttk.Entry(file_frame, textvariable=self.docx_path_var, state="readonly", width=70).pack(side="left", fill="x", expand=True, padx=(0, 5))
        ttk.Button(file_frame, text="Browse...", command=self.browse_docx).pack(side="left")
        subject_frame = ttk.LabelFrame(parent_frame, text="2. Set Default Subject", padding=(10, 5))
        subject_frame.pack(padx=10, pady=5, fill="x")
        ttk.Label(subject_frame, text="Subject for all questions in this file:").pack(side="left", padx=(0, 5))
        self.docx_subject_var = tk.StringVar()
        ttk.Entry(subject_frame, textvariable=self.docx_subject_var, width=50).pack(side="left", fill="x", expand=True)
        ttk.Button(parent_frame, text="Analyze DOCX File and Add to Queue", command=self.analyze_docx).pack(pady=20, ipadx=10, ipady=5)
        format_frame = ttk.LabelFrame(parent_frame, text="Required DOCX Format", padding=(10,5))
        format_frame.pack(padx=10, pady=10, fill="both", expand=True)
        format_text = "[start]\nDirection: ...\nQ1: ...\n(1) ...\n(2*) ...\n[end]"
        ttk.Label(format_frame, text=format_text, justify="left", font=("Courier", 10)).pack(anchor="w")

    def browse_docx(self):
        filepath = filedialog.askopenfilename(title="Select a Word Document", filetypes=(("Word Documents", "*.docx"), ("All files", "*.*")))
        if filepath: self.docx_path_var.set(filepath)

    def analyze_docx(self):
        filepath = self.docx_path_var.get()
        subject = self.docx_subject_var.get().strip()
        if not filepath: messagebox.showerror("Error", "Please select a DOCX file first."); return
        if not subject: messagebox.showerror("Error", "Please enter a default subject."); return
        try:
            doc = docx.Document(filepath)
            full_text = "\n".join([para.text for para in doc.paragraphs if para.text.strip()])
            blocks = [block.strip() for block in full_text.split('[start]') if block.strip()]
            questions_found_total = 0
            for block in blocks:
                block_content = block.split('[end]')[0].strip()
                direction = ""
                dir_match = re.search(r'Direction:(.*?)(?=Q\d+:)', block_content, re.IGNORECASE | re.DOTALL)
                if dir_match: direction = dir_match.group(1).strip()
                question_chunks = re.split(r'Q\d+:', block_content)[1:]
                for chunk in question_chunks:
                    chunk = chunk.strip()
                    if not chunk: continue
                    option_matches = re.findall(r'\((\d+)(\*?)\)\s*(.*?)(?=\s*\(\d+\*?\)|$)', chunk, re.DOTALL)
                    if not option_matches: continue
                    first_option_raw_text = f"({option_matches[0][0]}{option_matches[0][1]})"
                    question_text_end_index = chunk.find(first_option_raw_text)
                    question_text = chunk[:question_text_end_index].strip()
                    options_list = [{"optionText": text.strip(), "isCorrect": bool(star)} for num, star, text in option_matches]
                    if question_text and len(options_list) > 0:
                        final_data = {"subject": subject, "direction": direction, "questionText": question_text, "isPYQ": False, "options": options_list}
                        self.question_queue.append(final_data)
                        questions_found_total += 1
            if questions_found_total > 0:
                messagebox.showinfo("Success", f"{questions_found_total} questions were found and added to the queue.")
                self.queue_status_var.set(f"{len(self.question_queue)} questions in queue.")
            else:
                messagebox.showwarning("Warning", "No valid questions found in the selected file. Please check the formatting.")
        except Exception as e:
            messagebox.showerror("Error", f"An error occurred while reading the file: {e}")

if __name__ == "__main__":
    app = App()
    app.mainloop()