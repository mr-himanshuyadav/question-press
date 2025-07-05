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
from PIL import Image, ImageTk



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

class QuestionFrame(ttk.Frame):
    """A single, repeatable frame for one question's data."""
    def __init__(self, parent, remove_callback):
        super().__init__(parent, padding=(10, 5))
        self.pack(padx=10, pady=5, fill="x", expand=True)

        header = ttk.Frame(self)
        header.pack(fill="x", pady=(0, 10))
        ttk.Label(header, text="Question", font="-weight bold").pack(side="left")
        ttk.Button(header, text="Remove", command=remove_callback).pack(side="right")
        
        self.question_text = tk.Text(self, height=4, width=60, relief="solid", borderwidth=1)
        self.question_text.pack(fill="x", expand=True, pady=5)
        
        options_frame = ttk.LabelFrame(self, text="Options")
        options_frame.pack(fill="x", expand=True, pady=5)
        self.correct_option_var = tk.IntVar(value=0)
        self.option_vars = []
        for i in range(5):
            row = ttk.Frame(options_frame)
            row.pack(fill="x", pady=2)
            ttk.Radiobutton(row, variable=self.correct_option_var, value=i).pack(side="left")
            option_var = tk.StringVar()
            ttk.Entry(row, textvariable=option_var, width=80).pack(side="left", fill="x", expand=True, padx=5)
            self.option_vars.append(option_var)

    def get_data(self):
        text = self.question_text.get("1.0", "end-1c").strip()
        options = [var.get().strip() for var in self.option_vars if var.get().strip()]
        if not text or len(options) < 2: return None
        return {
            "questionId": str(uuid.uuid4()), "source": {"page": None, "number": None},
            "questionText": text, "isPYQ": False,
            "options": [{"optionText": opt, "isCorrect": i == self.correct_option_var.get()} for i, opt in enumerate(options)]
        }

class App(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("Question Press Kit"); self.geometry("950x850")
        self.question_groups, self.question_frame_widgets, self.current_group_image_path, self.image_preview_cache = [], [], None, {}
        notebook = ttk.Notebook(self); notebook.pack(pady=10, padx=10, expand=True, fill="both")
        manual_frame, docx_frame = ttk.Frame(notebook), ttk.Frame(notebook)
        manual_frame.pack(fill="both", expand=True); docx_frame.pack(fill="both", expand=True)
        notebook.add(manual_frame, text="Manual Question Entry"); notebook.add(docx_frame, text="Import from DOCX")
        self.create_manual_widgets(manual_frame); self.create_docx_import_widgets(docx_frame)

    def create_manual_widgets(self, parent):
        top_frame = ttk.Frame(parent); top_frame.pack(fill="x", side="top", padx=10, pady=(0,10))
        group_frame = ttk.LabelFrame(top_frame, text="Step 1: Define Group Details", padding=10)
        group_frame.pack(fill="x")
        ttk.Label(group_frame, text="Subject:").grid(row=0, column=0, sticky="w", padx=5, pady=5)
        self.manual_subject_var = tk.StringVar()
        ttk.Entry(group_frame, textvariable=self.manual_subject_var, width=40).grid(row=0, column=1, sticky="ew", padx=5)
        ttk.Label(group_frame, text="Direction Text:").grid(row=1, column=0, sticky="nw", padx=5, pady=5)
        self.direction_text = tk.Text(group_frame, height=3, width=60)
        self.direction_text.grid(row=1, column=1, columnspan=3, sticky="ew", padx=5)
        ttk.Label(group_frame, text="Direction Image:").grid(row=2, column=0, sticky="w", padx=5, pady=5)
        ttk.Button(group_frame, text="Upload Image...", command=self.upload_dir_image).grid(row=2, column=1, sticky="w", padx=5)
        self.image_preview_label = ttk.Label(group_frame, text="No image selected.")
        self.image_preview_label.grid(row=2, column=2, padx=5, sticky="w")
        group_frame.columnconfigure(1, weight=1)

        main_frame = ttk.Frame(parent); main_frame.pack(fill="both", expand=True, padx=10)
        canvas = tk.Canvas(main_frame); scrollbar = ttk.Scrollbar(main_frame, orient="vertical", command=canvas.yview)
        self.questions_container = ttk.Frame(canvas); self.questions_container.bind("<Configure>", lambda e: canvas.configure(scrollregion=canvas.bbox("all")))
        canvas.create_window((0, 0), window=self.questions_container, anchor="nw"); canvas.configure(yscrollcommand=scrollbar.set)
        canvas.pack(side="left", fill="both", expand=True); scrollbar.pack(side="right", fill="y")
        
        bottom_frame = ttk.Frame(parent); bottom_frame.pack(fill="x", side="bottom", padx=10, pady=10)
        ttk.Button(bottom_frame, text="+ Add New Question", command=self.add_question_frame).pack(side="left", padx=(0,10))
        ttk.Button(bottom_frame, text="Add Group to Queue", command=self.add_group_to_queue).pack(side="left")
        self.group_status_var = tk.StringVar(value="0 groups in queue."); ttk.Label(bottom_frame, textvariable=self.group_status_var, foreground="blue").pack(side="left", padx=20)
        ttk.Button(bottom_frame, text="Generate ZIP File...", command=self.generate_zip).pack(side="right")
        self.add_question_frame()

    def add_question_frame(self):
        qf = QuestionFrame(self.questions_container, lambda: self.remove_question_frame(qf)); self.question_frame_widgets.append(qf)
    def remove_question_frame(self, fr):
        if len(self.question_frame_widgets)>1: fr.destroy(); self.question_frame_widgets.remove(fr)
        else: messagebox.showwarning("Warning", "You must have at least one question.")

    def add_group_to_queue(self):
        subject = self.manual_subject_var.get().strip()
        if not subject: messagebox.showerror("Error", "Subject is required."); return
        questions_data = [qf.get_data() for qf in self.question_frame_widgets if qf.get_data()]
        if not questions_data: messagebox.showerror("Error", "No valid questions entered for this group."); return
        self.question_groups.append({"subject": subject, "direction_text": self.direction_text.get("1.0", "end-1c").strip(), "image_path": self.current_group_image_path, "questions": questions_data})
        self.group_status_var.set(f"{len(self.question_groups)} groups in queue."); self.reset_manual_form()
        messagebox.showinfo("Success", "Question group added to queue. Form has been reset.")

    def reset_manual_form(self):
        for w in self.question_frame_widgets: w.destroy()
        self.question_frame_widgets = []; self.current_group_image_path = None
        self.manual_subject_var.set(""); self.direction_text.delete("1.0", "end")
        self.image_preview_label.config(image='', text="No image selected."); self.add_question_frame()

    def upload_dir_image(self):
        fp = filedialog.askopenfilename(title="Select an Image", filetypes=[("Image Files", "*.png *.jpg *.jpeg")])
        if not fp: return
        self.current_group_image_path = fp
        try:
            img = Image.open(fp); img.thumbnail((100, 100))
            self.image_preview_cache['current'] = ImageTk.PhotoImage(img)
            self.image_preview_label.config(image=self.image_preview_cache['current'], text="")
        except: self.image_preview_label.config(image='', text="Preview failed.")

    def generate_zip(self):
        if not self.question_groups: messagebox.showwarning("Warning", "No groups created."); return
        filepath = filedialog.asksaveasfilename(defaultextension=".zip", filetypes=[("ZIP Archives", "*.zip")])
        if not filepath: return
        temp_dir, images_dir = "qp_temp_export", os.path.join("qp_temp_export", "images")
        if os.path.exists(temp_dir): shutil.rmtree(temp_dir)
        os.makedirs(images_dir, exist_ok=True)
        final_groups, temp_images = [], []
        for group in self.question_groups:
            img_name = None
            if group["image_path"]:
                try:
                    img_name = os.path.basename(group["image_path"])
                    dest_path = os.path.join(images_dir, img_name)
                    shutil.copy(group["image_path"], dest_path)
                    temp_images.append(dest_path)
                except: img_name = None
            final_groups.append({"groupId": str(uuid.uuid4()), "subject": group["subject"], "Direction": {"text": group["direction_text"], "image": img_name} if group["direction_text"] or img_name else None, "questions": group["questions"]})
        json_data = {"schemaVersion": "1.2", "exportTimestamp": datetime.utcnow().isoformat() + "Z", "sourceFile": os.path.basename(filepath), "questionGroups": final_groups}
        json_path = os.path.join(temp_dir, "questions.json")
        with open(json_path, 'w', encoding='utf-8') as f: json.dump(json_data, f, indent=2)
        with zipfile.ZipFile(filepath, 'w', zipfile.ZIP_DEFLATED) as zf:
            zf.write(json_path, 'questions.json')
            for img_path in temp_images: zf.write(img_path, os.path.join('images', os.path.basename(img_path)))
        shutil.rmtree(temp_dir)
        messagebox.showinfo("Success", "ZIP file generated successfully!"); self.question_groups = []
        self.group_status_var.set("0 groups in queue.")

    def create_docx_import_widgets(self, parent_frame):
        # This function's content is correct and unchanged
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
        ttk.Button(parent_frame, text="Analyze DOCX File and Generate ZIP", command=self.analyze_docx).pack(pady=20, ipadx=10, ipady=5)
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
            blocks_raw = full_text.split('[start]')[1:]
            
            imported_groups = []
            for block_text in blocks_raw:
                block_clean = block_text.split('[end]')[0].strip()
                direction = ""
                dir_match = re.search(r'Direction:(.*?)(?=Q\d+:|$)', block_clean, re.IGNORECASE | re.DOTALL)
                if dir_match: direction = dir_match.group(1).strip()
                
                question_chunks = re.split(r'\n?Q\d+:', block_clean)
                if len(question_chunks) > 0: question_chunks.pop(0)
                
                questions_in_group = []
                for chunk in question_chunks:
                    chunk = chunk.strip()
                    if not chunk: continue
                    option_matches = re.findall(r'\((\d+)(\*?)\)\s*(.*?)(?=\s*\(\d+\*?\)|$)', chunk, re.DOTALL)
                    if not option_matches: continue
                    first_option_raw_text = f"({option_matches[0][0]}{option_matches[0][1]})"
                    question_text = chunk.split(first_option_raw_text)[0].strip()
                    options_list = [{"optionText": text.strip(), "isCorrect": bool(star)} for _, star, text in option_matches]
                    if question_text and len(options_list) > 0:
                        questions_in_group.append({"questionText": question_text, "isPYQ": False, "options": options_list})
                
                if questions_in_group:
                    imported_groups.append({"subject": subject, "direction_text": direction, "image_path": None, "questions": questions_in_group})
            
            if not imported_groups:
                messagebox.showwarning("Warning", "No valid questions found in the file.")
                return

            self.question_groups = imported_groups
            self.generate_zip()

        except Exception as e:
            messagebox.showerror("Error", f"An error occurred: {e}")

if __name__ == "__main__":
    app = App()
    app.mainloop()