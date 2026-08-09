import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { useForm } from "react-hook-form";

import { toLaravelFieldErrors } from "@/api/errors";

import type { Person, PersonDetailsInput } from "../types/person";
import {
  personFormSchema,
  type PersonFormFields,
} from "../validation/personSchema";

type PersonFormProps = {
  person?: Person;
  submitLabel: string;
  pending: boolean;
  successMessage: string;
  onSubmit: (input: PersonDetailsInput) => Promise<unknown>;
};

function defaults(person?: Person): PersonFormFields {
  return {
    preferred_name: person?.preferred_name ?? "",
    alternate_names: person?.alternate_names.join(", ") ?? "",
    birth_precision: person?.birth_date.precision ?? "unknown",
    birth_value: person?.birth_date.value ?? "",
    is_deceased: person?.is_deceased ?? false,
    death_precision: person?.death_date.precision ?? "unknown",
    death_value: person?.death_date.value ?? "",
    biography: person?.biography ?? "",
  };
}

export function PersonForm({
  person,
  submitLabel,
  pending,
  successMessage,
  onSubmit,
}: PersonFormProps) {
  const [message, setMessage] = useState("");
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<PersonFormFields>({
    resolver: zodResolver(personFormSchema),
    defaultValues: defaults(person),
  });

  const submit = handleSubmit(async (fields) => {
    setMessage("");
    const input: PersonDetailsInput = {
      preferred_name: fields.preferred_name,
      alternate_names: fields.alternate_names
        .split(",")
        .map((name) => name.trim())
        .filter((name) => name !== ""),
      birth_date: {
        precision: fields.birth_precision,
        value: fields.birth_value || null,
      },
      is_deceased: fields.is_deceased,
      death_date: {
        precision: fields.death_precision,
        value: fields.death_value || null,
      },
      biography: fields.biography.trim() || null,
    };

    try {
      await onSubmit(input);
      setMessage(successMessage);
    } catch (error) {
      const fields = toLaravelFieldErrors(error);
      const fieldMap: Partial<Record<string, keyof PersonFormFields>> = {
        preferred_name: "preferred_name",
        alternate_names: "alternate_names",
        "birth_date.precision": "birth_precision",
        "birth_date.value": "birth_value",
        is_deceased: "is_deceased",
        "death_date.precision": "death_precision",
        "death_date.value": "death_value",
        biography: "biography",
      };
      for (const [backendField, message] of Object.entries(fields)) {
        const formField = backendField.startsWith("alternate_names.")
          ? "alternate_names"
          : fieldMap[backendField];
        if (formField !== undefined) {
          setError(formField, { message });
        }
      }
      setMessage(
        "The Person record could not be saved. Check the form and try again.",
      );
    }
  });

  return (
    <form onSubmit={(event) => void submit(event)}>
      <label htmlFor="preferred-name">Preferred name</label>
      <input
        id="preferred-name"
        aria-describedby={
          errors.preferred_name ? "preferred-name-error" : undefined
        }
        {...register("preferred_name")}
      />
      {errors.preferred_name && (
        <p id="preferred-name-error" role="alert">
          {errors.preferred_name.message}
        </p>
      )}

      <label htmlFor="alternate-names">Alternate names</label>
      <input
        id="alternate-names"
        placeholder="Comma-separated"
        aria-describedby={
          errors.alternate_names ? "alternate-names-error" : undefined
        }
        {...register("alternate_names")}
      />
      {errors.alternate_names && (
        <p id="alternate-names-error" role="alert">
          {errors.alternate_names.message}
        </p>
      )}

      <fieldset>
        <legend>Birth information</legend>
        <label htmlFor="birth-precision">Precision</label>
        <select id="birth-precision" {...register("birth_precision")}>
          <DatePrecisionOptions />
        </select>
        <label htmlFor="birth-value">Value</label>
        <input
          id="birth-value"
          placeholder="YYYY-MM-DD, YYYY-MM, YYYY or 1980s"
          aria-describedby={
            errors.birth_value ? "birth-value-error" : undefined
          }
          {...register("birth_value")}
        />
        {errors.birth_value && (
          <p id="birth-value-error" role="alert">
            {errors.birth_value.message}
          </p>
        )}
      </fieldset>

      <label className="check">
        <input type="checkbox" {...register("is_deceased")} /> Deceased
      </label>

      <fieldset>
        <legend>Death information</legend>
        <label htmlFor="death-precision">Precision</label>
        <select id="death-precision" {...register("death_precision")}>
          <DatePrecisionOptions />
        </select>
        {errors.death_precision && (
          <p role="alert">{errors.death_precision.message}</p>
        )}
        <label htmlFor="death-value">Value</label>
        <input
          id="death-value"
          placeholder="YYYY-MM-DD, YYYY-MM, YYYY or 1980s"
          aria-describedby={
            errors.death_value ? "death-value-error" : undefined
          }
          {...register("death_value")}
        />
        {errors.death_value && (
          <p id="death-value-error" role="alert">
            {errors.death_value.message}
          </p>
        )}
      </fieldset>

      <label htmlFor="biography">Biography and notes</label>
      <textarea id="biography" rows={5} {...register("biography")} />

      <button type="submit" disabled={pending}>
        {submitLabel}
      </button>
      {message !== "" && <p role="status">{message}</p>}
    </form>
  );
}

function DatePrecisionOptions() {
  return (
    <>
      <option value="unknown">Unknown</option>
      <option value="exact">Exact date</option>
      <option value="month">Month</option>
      <option value="year">Year</option>
      <option value="decade">Decade</option>
      <option value="approximate">Approximate date</option>
    </>
  );
}
